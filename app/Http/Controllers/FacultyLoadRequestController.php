<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewFacultyLoadRequestRequest;
use App\Http\Requests\StoreFacultyLoadRequestRequest;
use App\Models\Faculty;
use App\Models\FacultyLoadRequest;
use App\Services\NotificationService;
use App\Support\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Workflow for raising a Faculty member's teaching load ceiling when a
 * College is short-staffed.
 *
 * Admin/Registrar can still edit Faculty::max_teaching_units /
 * max_weekly_hours directly (FacultyController@update, gated by
 * FacultyPolicy::changeMaxLoad). Dean/OIC/Assistant Dean have NO direct
 * write path to those fields — this controller is the only door in for
 * them: submit a reasoned request, Admin/Registrar approves or denies.
 * Approval is the only thing that actually mutates the Faculty record.
 *
 * The review queue itself is now rendered inside FacultyController@index
 * (a section on the Faculty page) rather than its own screen — this
 * controller just handles the store/review actions.
 */
class FacultyLoadRequestController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Submit a new load change for a Faculty member.
     *
     * Dean/OIC/Assistant Dean have NO direct write path to
     * max_teaching_units/max_weekly_hours — for them this always
     * creates a Pending FacultyLoadRequest that sits in the queue
     * until Admin/Registrar reviews it.
     *
     * Admin/Registrar, on the other hand, already have a direct edit
     * path (FacultyController@update, gated by
     * FacultyPolicy::changeMaxLoad) — routing their own submission
     * through here as another Pending request would just mean they
     * (or another admin) has to separately go approve their own
     * change before it takes effect, which is a no-op approval step,
     * not a real review. So when the actor is Admin/Registrar, this
     * applies the change immediately: the record is created already
     * Approved/self-reviewed and the Faculty row is updated in the
     * same transaction, same as review() below.
     */
    public function store(StoreFacultyLoadRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', FacultyLoadRequest::class);

        $data = $request->validated();
        $faculty = Faculty::findOrFail($data['faculty_id']);

        // Requester must actually be allowed to see/manage this
        // faculty member (College scope) — create() only checked the
        // coarse role.
        $this->authorize('view', $faculty);

        $requestedUnits = $data['requested_max_teaching_units'] ?? null;
        $requestedHours = $data['requested_max_weekly_hours'] ?? null;

        if ($requestedUnits === null && $requestedHours === null) {
            return back()->withErrors(['requested_max_teaching_units' => 'Enter the units and/or hours you are requesting.'])->withInput();
        }

        if ($requestedUnits !== null && $requestedUnits <= $faculty->max_teaching_units) {
            return back()->withErrors(['requested_max_teaching_units' => 'The requested units must be higher than the faculty member\'s current maximum ('.$faculty->max_teaching_units.').'])->withInput();
        }

        $actorIsReviewer = AccessScope::isUnrestricted($request->user());
        $cap = FacultyLoadRequest::effectiveCapFor($request->user());

        DB::transaction(function () use ($faculty, $requestedUnits, $requestedHours, $data, $request, $actorIsReviewer, $cap) {
            $units = $requestedUnits !== null
                ? min($requestedUnits, $cap)
                : $faculty->max_teaching_units;

            $loadRequest = FacultyLoadRequest::create([
                'faculty_id' => $faculty->id,
                'current_max_teaching_units' => $faculty->max_teaching_units,
                'requested_max_teaching_units' => $units,
                'current_max_weekly_hours' => $faculty->max_weekly_hours,
                'requested_max_weekly_hours' => $requestedHours,
                'reason' => $data['reason'],
                'status' => $actorIsReviewer ? 'Approved' : 'Pending',
                'requested_by' => $request->user()->id,
                'reviewed_by' => $actorIsReviewer ? $request->user()->id : null,
                'reviewed_at' => $actorIsReviewer ? now() : null,
            ]);

            if ($actorIsReviewer) {
                $faculty->update([
                    'max_teaching_units' => $units,
                    'max_weekly_hours' => $requestedHours ?? $faculty->max_weekly_hours,
                ]);

                // No Pending step, so there's no separate reviewer to
                // notify — but the Dean/OIC of this faculty's College
                // still needs to know their faculty member's ceiling
                // just changed, even though they didn't submit
                // anything themselves.
                $this->notifications->facultyLoadUpdatedDirectly($loadRequest, $request->user());

                return;
            }

            $this->notifications->facultyLoadRequestSubmitted($loadRequest, $request->user());
        });

        return redirect()->route('scheduling.faculty')
            ->with('success', $actorIsReviewer
                ? 'Faculty load ceiling updated.'
                : 'Load change request submitted for review.');
    }

    /**
     * Admin/Registrar approves or denies a pending request. Approval
     * is the ONLY place outside FacultyController@update (Admin/
     * Registrar direct edit) that Faculty::max_teaching_units /
     * max_weekly_hours may change.
     */
    public function review(ReviewFacultyLoadRequestRequest $request, FacultyLoadRequest $facultyLoadRequest): RedirectResponse
    {
        $this->authorize('review', FacultyLoadRequest::class);

        if ($facultyLoadRequest->status !== 'Pending') {
            return back()->with('error', 'This request has already been reviewed.');
        }

        $data = $request->validated();

        DB::transaction(function () use ($data, $facultyLoadRequest, $request) {
            if ($data['decision'] === 'Approved') {
                // Hard institution-wide ceiling, enforced again here even
                // though ReviewFacultyLoadRequestRequest already capped it
                // at submission time — never trust a value that's been
                // sitting in the database (or in the request) unverified.
                // approved_max_teaching_units lets Admin/Registrar grant a
                // different ceiling than what was asked for; null falls
                // back to exactly what the Dean/OIC requested.
                $units = min(
                    $data['approved_max_teaching_units'] ?? $facultyLoadRequest->requested_max_teaching_units,
                    FacultyLoadRequest::effectiveCapFor($request->user()),
                );
                $hours = $data['approved_max_weekly_hours'] ?? $facultyLoadRequest->requested_max_weekly_hours ?? $facultyLoadRequest->faculty->max_weekly_hours;

                $facultyLoadRequest->faculty->update([
                    'max_teaching_units' => $units,
                    'max_weekly_hours' => $hours,
                ]);

                // Record what was actually granted, not just what was
                // asked for, so the Faculty Load Requests list and the
                // requester's notification reflect reality when a
                // reviewer adjusts the number.
                $facultyLoadRequest->requested_max_teaching_units = $units;
                $facultyLoadRequest->requested_max_weekly_hours = $hours;
            }

            $facultyLoadRequest->update([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'decision_note' => $data['decision_note'] ?? null,
            ]);

            $this->notifications->facultyLoadRequestReviewed($facultyLoadRequest, $request->user());
        });

        return back()->with('success', 'Request '.strtolower($data['decision']).'.');
    }

    /**
     * Remove an already-decided request from the list (cleanup only —
     * see FacultyLoadRequestPolicy::delete()). Pending requests are
     * rejected here even if a caller somehow bypasses the policy
     * check, since only review() may resolve those.
     */
    public function destroy(FacultyLoadRequest $facultyLoadRequest): RedirectResponse
    {
        $this->authorize('delete', $facultyLoadRequest);

        if ($facultyLoadRequest->status === 'Pending') {
            return back()->with('error', 'Approve or deny this request before removing it.');
        }

        $facultyLoadRequest->delete();

        return back()->with('success', 'Load request removed.');
    }
}