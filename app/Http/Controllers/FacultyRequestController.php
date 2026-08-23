<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewFacultyRequestRequest;
use App\Http\Requests\StoreFacultyCreationRequestRequest;
use App\Http\Requests\StoreFacultyDeactivationRequestRequest;
use App\Models\Faculty;
use App\Models\FacultyRequest;
use App\Services\FacultyWorkloadService;
use App\Services\NotificationService;
use App\Support\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Faculty Management request/approval workflow.
 *
 * Admin/Registrar still create/delete Faculty directly via
 * FacultyController@store / @destroy. Dean/OIC/Assistant Dean have NO
 * direct write path to either action — this controller is the only
 * door in for them: submit a reasoned request (Creation or
 * Deletion), Admin/Registrar approves or rejects. Approval is the
 * only thing that actually creates/deletes the Faculty record.
 *
 * Mirrors FacultyLoadRequestController's shape and transaction/audit/
 * notification conventions throughout.
 */
class FacultyRequestController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly FacultyWorkloadService $workloadService,
    ) {
    }

    /**
     * Submit a request to create a new Faculty member.
     *
     * The College is ALWAYS re-derived from the logged-in user's own
     * assigned college_id — never trusted from the request body (spec
     * Sections 2, 15). Assistant Dean requests are always General
     * Education/Minor (college_id null).
     */
    public function storeCreation(StoreFacultyCreationRequestRequest $request): RedirectResponse
    {
        $user = $request->user();
        $collegeId = AccessScope::isAssistantDean($user) ? null : $user->college_id;

        $this->authorize('requestCreate', [Faculty::class, $collegeId]);

        if (AccessScope::isCollegeScoped($user) && AccessScope::hasNoAssignedCollege($user)) {
            return back()->withErrors(['reason' => 'You have no assigned College. Contact an Administrator before submitting faculty requests.'])->withInput();
        }

        $data = $request->validated();
        $reason = $data['reason'];
        unset($data['reason']);

        // Never trust a "status" or "college_id" field, even if the
        // form somehow sent one — this is a Pending request, not an
        // active record, and the College is fixed above.
        unset($data['status'], $data['college_id']);

        $facultyRequest = DB::transaction(function () use ($data, $reason, $collegeId, $user) {
            $facultyRequest = FacultyRequest::create([
                'request_type' => 'Creation',
                'faculty_id' => null,
                'college_id' => $collegeId,
                'payload' => $data,
                'reason' => $reason,
                'status' => 'Pending',
                'requested_by' => $user->id,
            ]);

            $this->notifications->facultyRequestSubmitted($facultyRequest, $user);

            return $facultyRequest;
        });

        return redirect()->route('scheduling.faculty')
            ->with('success', 'Faculty creation request submitted for review.');
    }

    /**
     * Submit a request to permanently delete an existing Faculty
     * member. College scope is re-validated against the Faculty's OWN
     * college_id — never against anything in the request body.
     */
    public function storeDeactivation(StoreFacultyDeactivationRequestRequest $request, Faculty $faculty): RedirectResponse
    {
        $user = $request->user();

        $this->authorize('requestDeactivate', $faculty);

        // Duplicate-request guard — don't let the same Faculty
        // accumulate multiple simultaneous Pending Deletion requests.
        $hasPending = FacultyRequest::query()
            ->where('faculty_id', $faculty->id)
            ->where('request_type', 'Deletion')
            ->where('status', 'Pending')
            ->exists();

        if ($hasPending) {
            return back()->with('error', 'A deletion request for this faculty member is already pending review.');
        }

        $impact = $this->workloadService->deactivationImpact($faculty);
        $reason = $request->validated()['reason'];

        $facultyRequest = DB::transaction(function () use ($faculty, $impact, $reason, $user) {
            $facultyRequest = FacultyRequest::create([
                'request_type' => 'Deletion',
                'faculty_id' => $faculty->id,
                'college_id' => $faculty->college_id,
                'affected_summary' => $impact,
                'reason' => $reason,
                'status' => 'Pending',
                'requested_by' => $user->id,
            ]);

            $this->notifications->facultyRequestSubmitted($facultyRequest, $user);

            return $facultyRequest;
        });

        return redirect()->route('scheduling.faculty')
            ->with('success', $impact['has_active_assignments']
                ? 'Deletion request submitted for review. This faculty member currently has an active assigned subject — Admin/Registrar will review before deciding.'
                : 'Deletion request submitted for review.');
    }

    /**
     * Admin/Registrar approves or rejects a Pending request. Approval
     * is the ONLY place outside FacultyController@store/@destroy
     * (Admin/Registrar direct action) that a Faculty record may be
     * created or deactivated.
     */
    public function review(ReviewFacultyRequestRequest $request, FacultyRequest $facultyRequest): RedirectResponse
    {
        $this->authorize('review', FacultyRequest::class);

        if ($facultyRequest->status !== 'Pending') {
            return back()->with('error', 'This request has already been reviewed.');
        }

        $data = $request->validated();
        $reviewer = $request->user();

        if ($data['decision'] === 'Rejected') {
            DB::transaction(function () use ($facultyRequest, $data, $reviewer) {
                $facultyRequest->update([
                    'status' => 'Rejected',
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'decision_note' => $data['decision_note'] ?? null,
                ]);

                $this->notifications->facultyRequestReviewed($facultyRequest, $reviewer);
            });

            return back()->with('success', 'Request rejected.');
        }

        // Approval — branch by request type. Everything the frontend
        // warning already showed is re-derived and re-validated here,
        // never trusted as still current (spec Section 9/15).
        if ($facultyRequest->request_type === 'Creation') {
            return $this->approveCreation($facultyRequest, $data, $reviewer);
        }

        return $this->approveDeletion($facultyRequest, $data, $reviewer);
    }

    private function approveCreation(FacultyRequest $facultyRequest, array $data, \App\Models\User $reviewer): RedirectResponse
    {
        // Re-validate the College scope of the ORIGINAL requester one
        // more time before creating anything — the requester's own
        // college_id could theoretically have changed between
        // submission and review.
        $this->authorize('createForCollege', [Faculty::class, $facultyRequest->college_id]);

        $payload = $facultyRequest->payload ?? [];

        if (\App\Models\Faculty::where('faculty_id', $payload['faculty_id'] ?? null)->exists()) {
            return back()->with('error', 'A faculty member with this Faculty ID already exists. Reject this request and ask the requester to resubmit.');
        }

        DB::transaction(function () use ($facultyRequest, $payload, $data, $reviewer) {
            $faculty = Faculty::create([
                'faculty_id' => $payload['faculty_id'],
                'first_name' => $payload['first_name'],
                'middle_name' => $payload['middle_name'] ?? null,
                'last_name' => $payload['last_name'],
                'suffix' => $payload['suffix'] ?? null,
                'employment_type' => $payload['employment_type'],
                'college_id' => $facultyRequest->college_id,
                'max_teaching_units' => $payload['max_teaching_units'] ?? 24,
                'workload_type' => 'units',
                'status' => 'Active',
                'email' => $payload['email'] ?? null,
                'contact_number' => $payload['contact_number'] ?? null,
                'remarks' => $payload['qualifications_notes'] ?? $payload['availability_notes'] ?? null,
            ]);

            $facultyRequest->update([
                'faculty_id' => $faculty->id,
                'status' => 'Approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'decision_note' => $data['decision_note'] ?? null,
            ]);

            $this->notifications->facultyRequestReviewed($facultyRequest, $reviewer);
        });

        return back()->with('success', 'Faculty request approved — faculty member created.');
    }

    private function approveDeletion(FacultyRequest $facultyRequest, array $data, \App\Models\User $reviewer): RedirectResponse
    {
        $faculty = $facultyRequest->faculty;

        if (! $faculty) {
            return back()->with('error', 'The faculty member for this request no longer exists.');
        }

        // Recheck impact NOW, not the snapshot taken at submission —
        // assignments may have changed since.
        $impact = $this->workloadService->deactivationImpact($faculty);

        // Finalized-schedule protection — never silently delete a
        // faculty member still tied to a finalized/locked Section.
        // The reviewer must unlock/reassign first, then re-approve.
        if ($impact['has_finalized_assignment']) {
            return back()->with('error', 'This faculty member is assigned to a finalized schedule ('.implode(', ', $impact['finalized_section_codes']).'). Unlock the section and reassign before approving this deletion.');
        }

        DB::transaction(function () use ($facultyRequest, $faculty, $impact, $data, $reviewer) {
            $facultyRequest->update([
                'affected_summary' => $impact,
                'status' => 'Approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'decision_note' => $data['decision_note'] ?? null,
            ]);

            $this->notifications->facultyRequestReviewed($facultyRequest, $reviewer);

            if ($impact['has_active_assignments']) {
                $this->notifications->facultyAssignmentsNeedAttention($faculty, $impact, $reviewer, 'deleted');
            }

            $faculty->delete();
        });

        return back()->with('success', 'Deletion request approved — faculty member removed from the Faculty Master.');
    }

    /**
     * Withdraw/cancel a Pending request (requester, or Admin/Registrar
     * cleanup) — never used to bypass review() for an actual decision.
     */
    public function cancel(FacultyRequest $facultyRequest): RedirectResponse
    {
        $this->authorize('cancel', $facultyRequest);

        $facultyRequest->delete();

        return back()->with('success', 'Request cancelled.');
    }
}