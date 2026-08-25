<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendFacultyScheduleEmailRequest;
use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\FacultyScheduleEmail;
use App\Services\FacultyScheduleEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reports -> Faculty Schedule -> Send via Email (spec: Faculty Schedule
 * Email System). Sits alongside ReportsController rather than inside
 * it — this controller mutates (queues emails, writes history);
 * ReportsController stays read-only.
 */
class FacultyScheduleEmailController extends Controller
{
    public function __construct(private readonly FacultyScheduleEmailService $service) {}

    /**
     * Send (or version-bump / resend) a single faculty's finalized
     * schedule. Validation errors (missing/invalid email, not
     * finalized — spec sections 4/8/9) come back as normal Inertia
     * form errors for the modal to display.
     */
    public function send(SendFacultyScheduleEmailRequest $request): RedirectResponse
    {
        $faculty = Faculty::findOrFail($request->integer('faculty_id'));
        $term = AcademicTerm::findOrFail($request->integer('academic_term_id'));

        $record = $this->service->send($faculty, $term, $request->user());

        return back()->with('success', "Faculty schedule sent successfully to {$record->recipient_email}");
    }

    /**
     * Resend a specific past delivery (spec section 11 "Resend" /
     * section 17 "Retry" for failed sends).
     */
    public function resend(Request $request, FacultyScheduleEmail $facultyScheduleEmail): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['Administrator', 'Registrar']), 403);

        $record = $this->service->resend($facultyScheduleEmail, $request->user());

        return back()->with('success', "Faculty schedule re-sent to {$record->recipient_email}");
    }

    /**
     * "Send All Faculty Schedules" (spec section 15/16). Only queues
     * emails for faculty whose schedule is finalized and who have a
     * valid email — never sends synchronously in the request.
     */
    public function bulkSend(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['Administrator', 'Registrar']), 403);

        $term = AcademicTerm::findOrFail($request->integer('academic_term_id'));

        // faculty_ids is optional: omitted/empty means "every Active
        // faculty" (the original spec 15/16 behavior); present means
        // scope the send to whatever the Reports page currently has
        // filtered/selected (e.g. one college, or a specific
        // multi-select of faculty).
        $facultyIds = $request->input('faculty_ids');
        $facultyIds = is_array($facultyIds) && count($facultyIds) > 0
            ? array_map('intval', $facultyIds)
            : null;

        $result = $this->service->bulkSend($term, $request->user(), $facultyIds);

        return back()->with('success', "{$result['queued']} emails queued.")->with('bulkSendResult', $result);
    }

    /**
     * Email delivery history for one faculty + term (spec section 11).
     * Returned as JSON for the Email History panel on the Faculty
     * Schedule report.
     */
    public function history(Request $request, Faculty $faculty)
    {
        $termId = $request->integer('academic_term_id');

        $history = FacultyScheduleEmail::query()
            ->where('faculty_id', $faculty->id)
            ->when($termId, fn ($q) => $q->where('academic_term_id', $termId))
            ->with('sentBy:id,name')
            ->orderByDesc('created_at')
            ->get([
                'id', 'academic_term_id', 'recipient_email', 'schedule_version',
                'email_type', 'status', 'error_message', 'sent_by', 'sent_at', 'created_at',
            ]);

        return response()->json(['history' => $history]);
    }
}