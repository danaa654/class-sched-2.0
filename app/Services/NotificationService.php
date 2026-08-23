<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\FacultyRequest;
use App\Models\Notification;
use App\Models\ScheduleAuditLog;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\FacultyLoadRequest;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Collection;

/**
 * SCHEDULING NOTIFICATION SYSTEM — central service.
 *
 * The single place that creates Notification + ScheduleAuditLog rows.
 * Scheduling controllers/services call these methods; nothing else
 * should ever `Notification::create()` or `ScheduleAuditLog::create()`
 * directly, so recipient rules, priority, idempotency, and the
 * notification/audit split all stay consistent everywhere.
 *
 * TRANSACTION CONTRACT: every public method here must be called from
 * INSIDE the same DB::transaction() as the operation it reports on —
 * never before the write is known to succeed, never after commit. If
 * the outer transaction rolls back, these rows roll back with it for
 * free. Callers that are not already inside a transaction must wrap
 * the whole operation in DB::transaction() themselves — see
 * SectionController::finalize()/unlock()/store() for the pattern.
 *
 * ROLE AUDIT NOTE (2026-08-20 pass): the spec this pass implements
 * asks for a "Faculty" notification recipient (their own assignment/
 * schedule/availability changes). CLASSLY's Faculty records
 * (app/Models/Faculty.php) are NOT linked to a User account — there
 * is no `user_id` on Faculty, and RoleSeeder::ROLES has no "Faculty"
 * role. Faculty members don't log in to this system today, so there
 * is no recipient to notify. This service deliberately does not
 * invent a Faculty-facing notification path; if/when Faculty gets a
 * portal login, add a `faculty.user_id` FK and a recipientsForFaculty()
 * helper alongside recipientsFor() below.
 */
class NotificationService
{
    public function __construct(private readonly ActivityLogService $activityLog = new ActivityLogService) {}

    // Event types.
    public const TYPE_FINALIZED = 'SCHEDULE_FINALIZED';

    public const TYPE_UNLOCKED = 'SCHEDULE_UNLOCKED';

    public const TYPE_SCHEDULE_UPDATED = 'SCHEDULE_UPDATED';

    public const TYPE_CONFLICT = 'SCHEDULE_CONFLICT';

    public const TYPE_CONCURRENCY_CONFLICT = 'CONCURRENCY_CONFLICT';

    public const TYPE_SECTION_CREATED = 'SECTION_CREATED';

    public const TYPE_SUBJECT_ADDED = 'SUBJECT_ADDED';

    public const TYPE_SUBJECT_REMOVED = 'SUBJECT_REMOVED';

    public const TYPE_AUTO_SCHEDULE_COMPLETED = 'AUTO_SCHEDULE_COMPLETED';

    public const TYPE_AUTO_SCHEDULE_NEEDS_ATTENTION = 'AUTO_SCHEDULE_NEEDS_ATTENTION';

    // A Dean/OIC/Assistant Dean submitted (or Admin/Registrar decided)
    // a Faculty Load Request — see FacultyLoadRequestController. Not
    // Section-scoped like everything else above, so these two use the
    // lighter writeNotification() helper instead of dispatch().
    public const TYPE_FACULTY_LOAD_REQUEST_SUBMITTED = 'FACULTY_LOAD_REQUEST_SUBMITTED';

    public const TYPE_FACULTY_LOAD_REQUEST_REVIEWED = 'FACULTY_LOAD_REQUEST_REVIEWED';

    // Faculty Management request workflow (Creation/Deactivation
    // requests) — see FacultyRequestController. Same lighter
    // writeNotification() path as the Load Request pair above.
    public const TYPE_FACULTY_REQUEST_SUBMITTED = 'FACULTY_REQUEST_SUBMITTED';

    public const TYPE_FACULTY_REQUEST_REVIEWED = 'FACULTY_REQUEST_REVIEWED';

    public const TYPE_FACULTY_ASSIGNMENTS_NEED_ATTENTION = 'FACULTY_ASSIGNMENTS_NEED_ATTENTION';

    public const TYPE_FACULTY_DEACTIVATED_DIRECTLY = 'FACULTY_DEACTIVATED_DIRECTLY';

    public const TYPE_FACULTY_DELETED_DIRECTLY = 'FACULTY_DELETED_DIRECTLY';

    // Priority levels (spec Section 13). Never escalate to CRITICAL
    // for routine events — nothing in this service currently uses it;
    // it's reserved for a finalized schedule found invalid or a data
    // integrity problem, neither of which this pass introduces a
    // detector for.
    public const PRIORITY_INFO = 'INFO';

    public const PRIORITY_IMPORTANT = 'IMPORTANT';

    public const PRIORITY_WARNING = 'WARNING';

    public const PRIORITY_CRITICAL = 'CRITICAL';

    /**
     * A new Section was added to the schedule. Notifies the Dean/OIC
     * of the Section's College so they know a new block/section now
     * exists under their program before anyone starts scheduling it.
     */
    public function created(Section $section, User $actor): void
    {
        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_SECTION_CREATED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Section Created',
            message: "{$section->section_code} was added by {$actor->full_name}.",
            data: [
                'section_code' => $section->section_code,
                'section_type' => $section->section_type,
                'academic_year' => $section->academic_year,
                'semester' => $section->semester,
                'year_level' => $section->year_level,
            ],
            auditAction: 'SECTION_CREATED',
        );
    }

    /**
     * A finalized Section's schedule was locked. Notifies the
     * Dean/OIC of the Section's College. Spec Section 2A.
     */
    public function finalized(Section $section, User $actor): void
    {
        $subjectCount = $section->sectionSubjects()->count();

        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_FINALIZED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Schedule Finalized',
            message: "{$section->section_code} schedule finalized by {$actor->full_name}.",
            data: [
                'section_code' => $section->section_code,
                'academic_year' => $section->academic_year,
                'semester' => $section->semester,
                'finalized_by' => $actor->full_name,
                'subjects_scheduled' => $subjectCount,
                'finalized_at' => now()->toIso8601String(),
            ],
            auditAction: 'FINALIZED',
        );
    }

    /**
     * A finalized Section's schedule was unlocked, re-opening it for
     * editing. Notifies the Dean/OIC of the Section's College. Spec
     * Section 2B.
     */
    public function unlocked(Section $section, User $actor, string $reason): void
    {
        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_UNLOCKED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Schedule Unlocked',
            message: "{$section->section_code} schedule was unlocked by {$actor->full_name}. Reason: {$reason}",
            data: [
                'section_code' => $section->section_code,
                'unlocked_by' => $actor->full_name,
                'reason' => $reason,
            ],
            auditAction: 'UNLOCKED',
        );
    }

    /**
     * One or more fields on a SectionSubject's schedule changed in a
     * single save. Collects the field-level diffs into ONE
     * notification per save operation (spec Section 2C — never one
     * notification per field) and writes one audit row per changed
     * field (spec Section 15 — audit stays field-granular even though
     * the notification doesn't).
     *
     * @param  list<array{field: string, old: string|null, new: string|null}>  $changes
     */
    public function scheduleUpdated(Section $section, SectionSubject $subject, User $actor, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $subject->loadMissing('subject');
        $subjectCode = $subject->subject?->subject_code ?? "Subject #{$subject->subject_id}";

        $summaryLines = collect($changes)
            ->map(fn (array $change) => "{$change['field']}:\n".($change['old'] ?? '—').' → '.($change['new'] ?? '—'))
            ->all();

        $message = "{$section->section_code} schedule was modified.\n\n{$subjectCode}\n".implode("\n\n", $summaryLines);

        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_SCHEDULE_UPDATED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Schedule Modified',
            message: $message,
            data: [
                'section_code' => $section->section_code,
                'subject_code' => $subjectCode,
                'changes' => $changes,
            ],
            auditAction: 'SCHEDULE_UPDATED',
            sectionSubject: $subject,
            // One audit row per field changed — see docblock.
            auditFieldRows: $changes,
        );
    }

    /**
     * A Subject was added to a Section (manually, or via curriculum
     * generation). Spec Section 6. Notifies Dean/OIC.
     */
    public function subjectAdded(Section $section, SectionSubject $sectionSubject, User $actor): void
    {
        $sectionSubject->loadMissing('subject');
        $subjectCode = $sectionSubject->subject?->subject_code ?? "Subject #{$sectionSubject->subject_id}";

        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_SUBJECT_ADDED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Subject Added',
            message: "{$subjectCode} was added to {$section->section_code} by {$actor->full_name}.",
            data: ['section_code' => $section->section_code, 'subject_code' => $subjectCode],
            auditAction: 'SUBJECT_ADDED',
            sectionSubject: $sectionSubject,
        );
    }

    /**
     * A Subject was removed from a Section. Spec Section 6. Notifies
     * Dean/OIC. $subjectCode is passed in (rather than loaded from
     * $sectionSubject) because the row is already deleted by the time
     * this is called — see SectionSubjectController::destroy().
     */
    public function subjectRemoved(Section $section, string $subjectCode, User $actor): void
    {
        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_SUBJECT_REMOVED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Subject Removed',
            message: "{$subjectCode} was removed from {$section->section_code} by {$actor->full_name}.",
            data: ['section_code' => $section->section_code, 'subject_code' => $subjectCode],
            auditAction: 'SUBJECT_REMOVED',
        );
    }

    /**
     * An Auto Generate run finished. Spec Section 5 — COMPLETED
     * (IMPORTANT) when every subject was placed, NEEDS_ATTENTION
     * (WARNING) when some subjects couldn't be scheduled and need
     * manual attention. One notification per run, never per subject.
     *
     * @param  list<array{subject_code: string, reason: string}>  $unresolved
     */
    public function autoScheduleFinished(Section $section, User $actor, int $scheduled, int $total, array $unresolved): void
    {
        if ($total === 0) {
            // Nothing to schedule — not an event worth a notification
            // (spec Section 1: no notifications for insignificant
            // no-op actions).
            return;
        }

        $needsAttention = ! empty($unresolved);

        if ($needsAttention) {
            $unresolvedList = collect($unresolved)
                ->map(fn (array $row) => "• {$row['subject_code']}: {$row['reason']}")
                ->implode("\n");

            $this->dispatch(
                section: $section,
                actor: $actor,
                type: self::TYPE_AUTO_SCHEDULE_NEEDS_ATTENTION,
                priority: self::PRIORITY_WARNING,
                title: 'Auto Schedule Requires Attention',
                message: "Auto schedule for {$section->section_code} requires attention — {$scheduled} of {$total} subjects scheduled.\n\n{$unresolvedList}",
                data: [
                    'section_code' => $section->section_code,
                    'scheduled' => $scheduled,
                    'total' => $total,
                    'unresolved' => $unresolved,
                    'generated_by' => $actor->full_name,
                ],
                auditAction: 'AUTO_SCHEDULE_NEEDS_ATTENTION',
            );

            return;
        }

        $this->dispatch(
            section: $section,
            actor: $actor,
            type: self::TYPE_AUTO_SCHEDULE_COMPLETED,
            priority: self::PRIORITY_IMPORTANT,
            title: 'Auto Schedule Completed',
            message: "Auto schedule completed for {$section->section_code} — {$scheduled} of {$total} subjects scheduled by {$actor->full_name}.",
            data: [
                'section_code' => $section->section_code,
                'scheduled' => $scheduled,
                'total' => $total,
                'generated_by' => $actor->full_name,
            ],
            auditAction: 'AUTO_SCHEDULE_COMPLETED',
        );
    }

    /**
     * A Dean/OIC/Assistant Dean submitted a Faculty Load Request.
     * Notifies every Administrator/Registrar (they're the only ones
     * who can review it — see FacultyLoadRequestPolicy::review()) so
     * it shows up in the bell dropdown, not just when someone happens
     * to open the Faculty Load Requests modal.
     */
    public function facultyLoadRequestSubmitted(FacultyLoadRequest $loadRequest, User $actor): void
    {
        $loadRequest->loadMissing('faculty');
        $facultyName = trim(($loadRequest->faculty->first_name ?? '').' '.($loadRequest->faculty->last_name ?? ''));

        $recipients = $this->adminRecipients()->reject(fn (User $u) => $u->is($actor));

        foreach ($recipients as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_LOAD_REQUEST_SUBMITTED,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Load Request Submitted',
                message: "{$actor->full_name} requested a load increase for {$facultyName} ({$loadRequest->current_max_teaching_units} → {$loadRequest->requested_max_teaching_units} units).",
                data: [
                    'faculty_load_request_id' => $loadRequest->id,
                    'faculty_name' => $facultyName,
                    'current_max_teaching_units' => $loadRequest->current_max_teaching_units,
                    'requested_max_teaching_units' => $loadRequest->requested_max_teaching_units,
                ],
            );
        }
    }

    /**
     * Admin/Registrar approved or denied a Faculty Load Request.
     * Notifies whoever originally submitted it (requested_by) so
     * they're not left checking the modal to find out.
     */
    public function facultyLoadRequestReviewed(FacultyLoadRequest $loadRequest, User $actor): void
    {
        $loadRequest->loadMissing('faculty', 'requestedBy');
        $recipient = $loadRequest->requestedBy;

        // Admin/Registrar reviewing their own submission (they're
        // also allowed to create requests, see
        // FacultyLoadRequestPolicy::create()) — no self-notification.
        if (! $recipient || $recipient->is($actor)) {
            return;
        }

        $facultyName = trim(($loadRequest->faculty->first_name ?? '').' '.($loadRequest->faculty->last_name ?? ''));
        $decision = $loadRequest->status; // 'Approved' | 'Denied'

        $message = "Your load increase request for {$facultyName} was {$decision} by {$actor->full_name}.";
        if ($loadRequest->decision_note) {
            $message .= " Note: {$loadRequest->decision_note}";
        }

        $this->writeNotification(
            recipient: $recipient,
            actor: $actor,
            type: self::TYPE_FACULTY_LOAD_REQUEST_REVIEWED,
            priority: self::PRIORITY_IMPORTANT,
            title: "Faculty Load Request {$decision}",
            message: $message,
            data: [
                'faculty_load_request_id' => $loadRequest->id,
                'faculty_name' => $facultyName,
                'status' => $decision,
                'decision_note' => $loadRequest->decision_note,
            ],
        );
    }

    /**
     * Admin/Registrar applied a load change directly, with no Pending
     * step for anyone to review (see FacultyLoadRequestController::
     * store()'s actorIsReviewer branch — they already have direct
     * edit rights, so there's no separate approval to notify anyone
     * about). The Dean/OIC of the Faculty's College (or Assistant
     * Dean, if the Faculty has no College — General Education) still
     * needs to know their faculty member's ceiling changed, even
     * though nobody on their end had to request it — same courtesy
     * facultyLoadRequestReviewed() gives a Dean/OIC whose own
     * submission was decided, just for the case where nothing was
     * submitted in the first place.
     */
    public function facultyLoadUpdatedDirectly(FacultyLoadRequest $loadRequest, User $actor): void
    {
        $loadRequest->loadMissing('faculty');
        $faculty = $loadRequest->faculty;
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->collegeRecipientsForFaculty($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_LOAD_REQUEST_REVIEWED,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Load Updated',
                message: "{$actor->full_name} updated {$facultyName}'s teaching load ceiling ({$loadRequest->current_max_teaching_units} → {$loadRequest->requested_max_teaching_units} units).",
                data: [
                    'faculty_load_request_id' => $loadRequest->id,
                    'faculty_name' => $facultyName,
                    'current_max_teaching_units' => $loadRequest->current_max_teaching_units,
                    'requested_max_teaching_units' => $loadRequest->requested_max_teaching_units,
                ],
            );
        }
    }

    /**
     * Admin/Registrar changed a faculty member's Maximum Teaching
     * Units straight from the Edit Faculty form (FacultyController::
     * update()) — a plain roster edit, not the Faculty Load Request
     * workflow, so there's no FacultyLoadRequest row to attach this
     * to. Still notifies the Dean/OIC of the Faculty's College (or
     * Assistant Dean for General Education/no-College faculty) so
     * they're not blindsided by their faculty member's ceiling
     * changing with no request/approval trail at all. Only call this
     * when the value actually changed — see FacultyController::
     * update().
     */
    public function facultyMaxLoadEditedDirectly(Faculty $faculty, User $actor, int $oldUnits, int $newUnits): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->collegeRecipientsForFaculty($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_LOAD_REQUEST_REVIEWED,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Load Updated',
                message: "{$actor->full_name} updated {$facultyName}'s teaching load ceiling ({$oldUnits} → {$newUnits} units) from the Faculty Master.",
                data: [
                    'faculty_id' => $faculty->id,
                    'faculty_name' => $facultyName,
                    'current_max_teaching_units' => $oldUnits,
                    'requested_max_teaching_units' => $newUnits,
                ],
            );
        }
    }

    /**
     * A Scheduling operation failed because of a Room/Faculty/Section
     * conflict rejected by validate() before any write. Optional per
     * spec Section 3 — notifies Admin/Registrar only, never the
     * Dean/OIC (a failed operation isn't "their" event), and only for
     * conflicts a caller judges worth surfacing (spec explicitly says
     * not to fire this on every failed drag/drop — see
     * SectionSubjectController, which does NOT call this from the
     * routine `$conflictErrors` 422 path, only from concurrency
     * rejections via concurrencyConflict() below). No audit row: a
     * failed/rolled-back operation never happened as far as the audit
     * trail is concerned.
     */
    public function conflict(Section $section, User $actor, string $summary): void
    {
        $this->dispatchToAdmins(
            section: $section,
            actor: $actor,
            type: self::TYPE_CONFLICT,
            priority: self::PRIORITY_WARNING,
            title: 'Schedule Conflict',
            message: $summary,
        );
    }

    /**
     * Two users raced to write the same Section's schedule and the
     * second (this) request lost — rejected under
     * ScheduleConflictService::checkSectionVersion()'s optimistic
     * lock. Spec Section 4: "do not create duplicate notifications
     * for the same concurrency conflict" — the existing 5s dedup
     * window in dispatchToAdmins() covers a retry storm from the same
     * losing actor; a genuinely new race a few seconds later is rare
     * enough to be worth its own notification.
     */
    public function concurrencyConflict(Section $section, User $actor, string $summary): void
    {
        $this->dispatchToAdmins(
            section: $section,
            actor: $actor,
            type: self::TYPE_CONCURRENCY_CONFLICT,
            priority: self::PRIORITY_WARNING,
            title: 'Concurrent Scheduling Conflict',
            message: $summary,
        );
    }

    /**
     * Shared plumbing behind finalized()/unlocked()/scheduleUpdated()/
     * subjectAdded()/subjectRemoved()/autoScheduleFinished(): resolve
     * recipients, write one Notification per recipient, write the
     * audit row(s), all idempotency-guarded against duplicate
     * double-click/retry submissions of the same logical operation.
     *
     * @param  list<array{field: string, old: string|null, new: string|null}>|null  $auditFieldRows
     */
    /**
     * A Dean/OIC/Assistant Dean submitted a Faculty Creation or
     * Deletion request. Notifies every Administrator/Registrar
     * (they're the only ones who can review it — see
     * FacultyRequestPolicy::review()).
     */
    public function facultyRequestSubmitted(FacultyRequest $facultyRequest, User $actor): void
    {
        $label = $facultyRequest->request_type === 'Creation' ? 'creation' : 'deletion';
        $subject = $facultyRequest->request_type === 'Creation'
            ? trim(($facultyRequest->payload['first_name'] ?? '').' '.($facultyRequest->payload['last_name'] ?? ''))
            : trim(($facultyRequest->faculty?->first_name ?? '').' '.($facultyRequest->faculty?->last_name ?? ''));

        foreach ($this->adminRecipients()->reject(fn (User $u) => $u->is($actor)) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_REQUEST_SUBMITTED,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty '.ucfirst($label).' Request Submitted',
                message: "{$actor->full_name} requested a faculty {$label} for {$subject}.",
                data: [
                    'faculty_request_id' => $facultyRequest->id,
                    'request_type' => $facultyRequest->request_type,
                    'faculty_name' => $subject,
                ],
            );
        }

        $this->auditFacultyRequest($actor, 'FACULTY_'.strtoupper($label).'_REQUEST_SUBMITTED', $facultyRequest);
    }

    /**
     * Admin/Registrar approved or rejected a Faculty Creation or
     * Deletion request. Notifies whoever originally submitted it.
     */
    public function facultyRequestReviewed(FacultyRequest $facultyRequest, User $actor): void
    {
        $facultyRequest->loadMissing('requestedBy', 'faculty');
        $recipient = $facultyRequest->requestedBy;

        $label = $facultyRequest->request_type === 'Creation' ? 'creation' : 'deletion';
        $subject = $facultyRequest->request_type === 'Creation'
            ? trim(($facultyRequest->payload['first_name'] ?? '').' '.($facultyRequest->payload['last_name'] ?? ''))
            : trim(($facultyRequest->faculty?->first_name ?? '').' '.($facultyRequest->faculty?->last_name ?? ''));

        $decision = $facultyRequest->status; // 'Approved' | 'Rejected'
        $message = "Your faculty {$label} request for {$subject} was {$decision} by {$actor->full_name}.";
        if ($facultyRequest->decision_note) {
            $message .= " Note: {$facultyRequest->decision_note}";
        }

        if ($recipient && ! $recipient->is($actor)) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_REQUEST_REVIEWED,
                priority: self::PRIORITY_IMPORTANT,
                title: "Faculty ".ucfirst($label)." Request {$decision}",
                message: $message,
                data: [
                    'faculty_request_id' => $facultyRequest->id,
                    'request_type' => $facultyRequest->request_type,
                    'faculty_name' => $subject,
                    'status' => $decision,
                    'decision_note' => $facultyRequest->decision_note,
                ],
            );
        }

        $this->auditFacultyRequest($actor, 'FACULTY_'.strtoupper($label).'_REQUEST_'.strtoupper($decision), $facultyRequest);
    }

    /**
     * A Faculty member with active assignments was just deactivated
     * (via an approved request OR a direct Admin/Registrar action)
     * and those assignments now need manual attention — no automatic
     * reassignment happens (spec Section 11). Notifies the Dean/OIC/
     * Assistant Dean of the Faculty's College so the vacancy doesn't
     * go unnoticed until the next schedule run.
     *
     * @param  array<string, mixed>  $impact  FacultyWorkloadService::deactivationImpact() output.
     * @param  string  $action  Past-tense verb describing what just happened to the faculty ('deactivated' or 'deleted').
     */
    public function facultyAssignmentsNeedAttention(Faculty $faculty, array $impact, User $actor, string $action = 'deactivated'): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->collegeRecipientsForFaculty($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_ASSIGNMENTS_NEED_ATTENTION,
                priority: self::PRIORITY_WARNING,
                title: 'Faculty Assignment Requires Attention',
                message: "{$facultyName} was {$action} with {$impact['subject_count']} active subject(s) across {$impact['section_count']} section(s) — these are now vacant and need reassignment.",
                data: [
                    'faculty_name' => $facultyName,
                    'subject_codes' => $impact['subject_codes'],
                    'section_codes' => $impact['section_codes'],
                ],
            );
        }
    }

    /**
     * Admin/Registrar deactivated a Faculty member directly
     * (FacultyController@destroy), bypassing the request workflow
     * entirely since they're already authorized to. Notifies the
     * Dean/OIC/Assistant Dean of that Faculty's College so they're
     * not blindsided, same courtesy facultyMaxLoadEditedDirectly()
     * gives for direct load-ceiling edits.
     */
    public function facultyDeactivatedDirectly(Faculty $faculty, User $actor): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->collegeRecipientsForFaculty($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_DEACTIVATED_DIRECTLY,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Deactivated',
                message: "{$actor->full_name} deactivated {$facultyName}.",
                data: ['faculty_name' => $facultyName],
            );
        }

        ScheduleAuditLog::create([
            'user_id' => $actor->id,
            'action' => 'FACULTY_DEACTIVATED_DIRECTLY',
            'section_id' => null,
            'section_subject_id' => null,
            'field' => 'status',
            'old_value' => 'Active',
            'new_value' => 'Inactive',
            'created_at' => now(),
        ]);

        $this->activityLog->record(
            ActivityLogService::FACULTY_DEACTIVATED,
            "{$actor->full_name} deactivated faculty member {$facultyName}.",
            $faculty,
            $actor,
        );
    }

    /**
     * Admin/Registrar permanently deleted a Faculty member from the
     * roster (FacultyController@destroy — a soft delete, see that
     * method's docblock). Notifies the Dean/OIC/Assistant Dean of
     * that Faculty's College so they're not blindsided, and leaves an
     * audit trail distinct from a plain deactivation.
     */
    public function facultyDeletedDirectly(Faculty $faculty, User $actor): void
    {
        $facultyName = trim(($faculty->first_name ?? '').' '.($faculty->last_name ?? ''));

        foreach ($this->collegeRecipientsForFaculty($faculty, $actor) as $recipient) {
            $this->writeNotification(
                recipient: $recipient,
                actor: $actor,
                type: self::TYPE_FACULTY_DELETED_DIRECTLY,
                priority: self::PRIORITY_IMPORTANT,
                title: 'Faculty Deleted',
                message: "{$actor->full_name} deleted {$facultyName} from the Faculty Master.",
                data: ['faculty_name' => $facultyName],
            );
        }

        ScheduleAuditLog::create([
            'user_id' => $actor->id,
            'action' => 'FACULTY_DELETED_DIRECTLY',
            'section_id' => null,
            'section_subject_id' => null,
            'field' => 'status',
            'old_value' => $faculty->status,
            'new_value' => 'Deleted',
            'created_at' => now(),
        ]);

        $this->activityLog->record(
            ActivityLogService::FACULTY_DELETED,
            "{$actor->full_name} removed faculty member {$facultyName} from the Faculty Master.",
            $faculty,
            $actor,
        );
    }

    /**
     * Generic (non-Section-scoped) audit row for the Faculty
     * Management request workflow — same table as audit(), just
     * without a Section to attach to (schedule_audit_logs.section_id
     * is nullable for exactly this reason).
     */
    private function auditFacultyRequest(User $actor, string $action, FacultyRequest $facultyRequest): void
    {
        ScheduleAuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'section_id' => null,
            'section_subject_id' => null,
            'field' => 'faculty_request_id',
            'old_value' => null,
            'new_value' => (string) $facultyRequest->id,
            'created_at' => now(),
        ]);
    }

    private function dispatch(
        Section $section,
        User $actor,
        string $type,
        string $priority,
        string $title,
        string $message,
        array $data,
        string $auditAction,
        ?SectionSubject $sectionSubject = null,
        ?array $auditFieldRows = null,
    ): void {
        // DUPLICATE-NOTIFICATION GUARD (spec Section 17) — a
        // double-click, network retry, or duplicate frontend request
        // for the exact same logical operation (same Section, same
        // type, same actor) within a short window must not fan out
        // into repeated notifications/audit rows. A real second
        // finalize/unlock/update a few seconds later is vanishingly
        // unlikely and, if it happens, is itself worth deduping.
        if ($this->isRecentDuplicate($section, $type, $actor)) {
            return;
        }

        $recipients = $this->recipientsFor($section, $actor);

        foreach ($recipients as $recipient) {
            $this->create($recipient, $actor, $section, $sectionSubject, $type, $priority, $title, $message, $data);
        }

        if ($auditFieldRows !== null) {
            foreach ($auditFieldRows as $change) {
                $this->audit($actor, $auditAction, $section, $sectionSubject, $change['field'], $change['old'], $change['new']);
            }
        } else {
            $this->audit($actor, $auditAction, $section, $sectionSubject);
        }

        // One Activity Log row per save (never per changed field —
        // that finer granularity already lives in schedule_audit_logs
        // above). Only the first line of $message is used — for
        // scheduleUpdated() specifically, $message spans several
        // lines listing every field diff, which belongs in
        // schedule_audit_logs, not in this general-purpose log line.
        $this->activityLog->record(
            $this->activityLogActionFor($auditAction),
            "{$title}: ".strtok($message, "\n"),
            $section,
            $actor,
        );
    }

    /**
     * Maps a ScheduleAuditLog action code (this service's internal
     * vocabulary) to the corresponding App\Services\ActivityLogService
     * action code (the Activity Log tab's vocabulary). Falls back to
     * the original code unchanged for anything not explicitly listed.
     */
    private function activityLogActionFor(string $auditAction): string
    {
        return match ($auditAction) {
            'SECTION_CREATED' => ActivityLogService::SECTION_CREATED,
            'FINALIZED' => ActivityLogService::SECTION_FINALIZED,
            'UNLOCKED' => ActivityLogService::SECTION_UNLOCKED,
            'SCHEDULE_UPDATED' => ActivityLogService::SCHEDULE_UPDATED,
            'SUBJECT_ADDED' => ActivityLogService::SUBJECT_ADDED_TO_SECTION,
            'SUBJECT_REMOVED' => ActivityLogService::SUBJECT_REMOVED_FROM_SECTION,
            default => $auditAction,
        };
    }

    /**
     * Same dedup + audit-free plumbing as dispatch(), but for
     * Admin/Registrar-only events (conflict()/concurrencyConflict())
     * that never write an audit row — a rejected operation didn't
     * change anything, so there's nothing to audit.
     */
    private function dispatchToAdmins(
        Section $section,
        User $actor,
        string $type,
        string $priority,
        string $title,
        string $message,
    ): void {
        if ($this->isRecentDuplicate($section, $type, $actor)) {
            return;
        }

        $recipients = $this->adminRecipients()->reject(fn (User $u) => $u->is($actor));

        foreach ($recipients as $recipient) {
            $this->create($recipient, $actor, $section, null, $type, $priority, $title, $message, [
                'section_code' => $section->section_code,
            ]);
        }
    }

    private function isRecentDuplicate(Section $section, string $type, User $actor): bool
    {
        return Notification::query()
            ->where('section_id', $section->id)
            ->where('type', $type)
            ->where('actor_user_id', $actor->id)
            ->where('created_at', '>=', now()->subSeconds(5))
            ->exists();
    }

    private function create(
        User $recipient,
        User $actor,
        Section $section,
        ?SectionSubject $sectionSubject,
        string $type,
        string $priority,
        string $title,
        string $message,
        array $data,
    ): Notification {
        return Notification::create([
            'recipient_user_id' => $recipient->id,
            'actor_user_id' => $actor->id,
            'type' => $type,
            'priority' => $priority,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'section_id' => $section->id,
            'section_subject_id' => $sectionSubject?->id,
            'is_read' => false,
        ]);
    }

    /**
     * Same write as create(), but for notifications with no Section
     * to attach to (facultyLoadRequestSubmitted()/Reviewed() above —
     * `section_id`/`section_subject_id` are nullable in the schema
     * precisely for this case). No dedup guard here since these two
     * callers each only fire once per store()/review() request.
     */
    private function writeNotification(
        User $recipient,
        User $actor,
        string $type,
        string $priority,
        string $title,
        string $message,
        array $data,
    ): Notification {
        return Notification::create([
            'recipient_user_id' => $recipient->id,
            'actor_user_id' => $actor->id,
            'type' => $type,
            'priority' => $priority,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'is_read' => false,
        ]);
    }

    private function audit(
        User $actor,
        string $action,
        Section $section,
        ?SectionSubject $sectionSubject,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): void {
        ScheduleAuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'section_id' => $section->id,
            'section_subject_id' => $sectionSubject?->id,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'created_at' => now(),
        ]);
    }

    /**
     * RECIPIENT RULES (role + College scoping, spec Sections 2, 9,
     * 18) — every finalize/unlock/schedule-update/subject/section/
     * auto-schedule notification is resolved from TWO recipient
     * groups, combined and deduplicated:
     *
     *   1. College-scoped: Dean/OIC of the Section's own College
     *      (AccessScope::COLLEGE_SCOPED_ROLES, filtered by
     *      users.college_id). A Dean of CTE never sees a CCS event.
     *   2. Institution-wide: Administrator + Registrar
     *      (AccessScope::UNRESTRICTED_ROLES) — always included,
     *      regardless of which College the Section belongs to,
     *      because both roles operate institution-wide (spec
     *      Section 9).
     *
     * Never hardcoded ids, never "notify every user" — both groups
     * are resolved live from AccessScope's role/scope model. The two
     * groups are concatenated and deduped by user id (spec Section
     * 11 — a user who happens to qualify through both a College role
     * and an institution-wide role still gets exactly one
     * notification), then the actor is excluded (spec Section 10 —
     * enforced here at the backend, never left to the frontend to
     * hide).
     *
     * Assistant Dean is deliberately NOT included: per
     * RoleSeeder/AccessScope, Assistant Dean is an institution-wide
     * GenEd/Minor role, not bound to a single College the way
     * Dean/OIC are — routing Section-level events to them would be
     * "notify everyone" by another name. If GenEd/Minor-specific
     * notifications are added later, give them their own resolver
     * rather than folding them in here.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(Section $section, User $actor): Collection
    {
        $section->loadMissing('major.department.college');
        $collegeId = $section->major?->college()?->id;

        $collegeScoped = $collegeId
            ? User::query()
                ->role(AccessScope::COLLEGE_SCOPED_ROLES)
                ->where('college_id', $collegeId)
                ->get()
            : collect();

        $institutionWide = User::query()->role(AccessScope::UNRESTRICTED_ROLES)->get();

        return $collegeScoped
            ->concat($institutionWide)
            // Same user reachable through both groups (e.g. an
            // Administrator who is also somehow College-scoped) —
            // exactly one notification, not two (spec Section 11).
            ->unique('id')
            // Don't notify someone of their own action (spec
            // Section 10).
            ->reject(fn (User $u) => $u->is($actor))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function adminRecipients(): Collection
    {
        return User::query()->role(AccessScope::UNRESTRICTED_ROLES)->get();
    }

    /**
     * Dean/OIC of the Faculty's College — or Assistant Dean, when the
     * Faculty has no College (General Education/Minor, same scoping
     * FacultyLoadRequestPolicy::view() uses). Deliberately NOT
     * concatenated with institution-wide Admin/Registrar the way
     * recipientsFor() does for Sections — the actor here already IS
     * Admin/Registrar, so notifying "every Admin/Registrar" would
     * mostly just be notifying the actor's own peers about the
     * actor's own action; this is specifically about reaching the
     * College-side people who had no part in it.
     *
     * @return Collection<int, User>
     */
    private function collegeRecipientsForFaculty(Faculty $faculty, User $actor): Collection
    {
        $recipients = $faculty->college_id
            ? User::query()->role(AccessScope::COLLEGE_SCOPED_ROLES)->where('college_id', $faculty->college_id)->get()
            : User::query()->role(AccessScope::ASSISTANT_DEAN_ROLE)->get();

        return $recipients->reject(fn (User $u) => $u->is($actor))->values();
    }
}