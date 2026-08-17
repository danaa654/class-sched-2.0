<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\ScheduleAuditLog;
use App\Models\Section;
use App\Models\SectionSubject;
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
     * A scheduling operation failed because of a Room/Faculty/Section
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
     * RECIPIENT RULES (spec Section 12) — Dean/OIC of the Section's
     * own College get finalize/unlock/schedule-update/subject/section
     * notifications. Never every user, never hardcoded ids — always
     * resolved from the existing role/College-scope system in
     * AccessScope. Admin/Registrar are deliberately NOT included here
     * — they get their own separate notification path
     * (adminRecipients()/dispatchToAdmins()) for conflicts/failures
     * only, per spec Section 12's Admin/Registrar list being distinct
     * from the Dean/OIC list.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(Section $section, User $actor): Collection
    {
        $section->loadMissing('major.department.college');
        $collegeId = $section->major?->college()?->id;

        if (! $collegeId) {
            return collect();
        }

        return User::query()
            ->role(AccessScope::COLLEGE_SCOPED_ROLES)
            ->where('college_id', $collegeId)
            ->get()
            // Don't notify someone of their own action.
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
}