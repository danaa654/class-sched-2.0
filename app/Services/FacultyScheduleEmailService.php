<?php

namespace App\Services;

use App\Jobs\SendFacultyScheduleEmailJob;
use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\FacultyScheduleEmail;
use App\Models\SectionSubject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Faculty Schedule Email System (spec: Faculty Schedule Email System
 * prompt). Reuses the same SectionSubject data ReportsService's
 * "Schedule by Faculty" report already queries — this service does not
 * introduce a second source of truth for what a faculty member teaches.
 */
class FacultyScheduleEmailService
{
    /**
     * A schedule is only "finalized" (sendable) once every one of the
     * faculty's assigned classes for the term has faculty/room/day/time
     * all set — matches the "fully scheduled" definition Block Schedule
     * already reports against.
     */
    public function isFinalized(Faculty $faculty, AcademicTerm $term): bool
    {
        $rows = $this->scheduleRows($faculty, $term);

        if ($rows->isEmpty()) {
            return false;
        }

        return $rows->every(fn (SectionSubject $ss) => $ss->faculty_id
            && $ss->room_id
            && $ss->days
            && $ss->start_time
            && $ss->end_time);
    }

    /**
     * @return Collection<int, SectionSubject>
     */
    public function scheduleRows(Faculty $faculty, AcademicTerm $term): Collection
    {
        // `sections` has no academic_term_id column — it stores
        // academic_year + semester as plain strings, spelled
        // differently from the Semester model (see
        // AcademicTerm::sectionSemesterValue()). Reuse the same
        // matchingSectionsQuery() every other report/service already
        // relies on for this mapping, instead of filtering on a
        // column that doesn't exist.
        $sectionIds = $term->matchingSectionsQuery()->pluck('id');

        return SectionSubject::query()
            ->where('faculty_id', $faculty->id)
            ->where('is_merged', false)
            ->whereIn('section_id', $sectionIds)
            ->with(['section.major.department.college', 'subject', 'room'])
            ->get();
    }

    public function buildSnapshot(Collection $rows): array
    {
        return $rows->map(fn (SectionSubject $ss) => [
            // Subject uses subject_code/subject_title (not code/title —
            // see Subject::$fillable), and Room uses room_name (not
            // name — see Room::$fillable). Using the wrong attribute
            // names here silently returned null, which is why Subject
            // Title and Room came back blank on the PDF/email.
            'subject_code' => $ss->subject?->subject_code ?? $ss->edp_code,
            'subject_title' => $ss->subject?->subject_title,
            'section' => $ss->section?->section_code ?? $ss->section?->section_name,
            'room' => $ss->room?->room_name,
            'days' => $ss->days,
            'start_time' => $ss->start_time,
            'end_time' => $ss->end_time,
        ])->values()->all();
    }

    /**
     * Validate everything spec sections 4/8/9 require, then queue the
     * email. Returns the FacultyScheduleEmail history row (status
     * 'pending' until the job flips it to sent/failed).
     *
     * @throws ValidationException
     */
    public function send(Faculty $faculty, AcademicTerm $term, User $sender): FacultyScheduleEmail
    {
        if (! $faculty->email) {
            throw ValidationException::withMessages([
                'email' => 'This faculty member does not have an email address. Add an email address to the faculty profile before sending the schedule.',
            ]);
        }

        if (! filter_var($faculty->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'The email address stored for this faculty member is not valid. Please update the faculty profile.',
            ]);
        }

        if (! $this->isFinalized($faculty, $term)) {
            throw ValidationException::withMessages([
                'schedule' => 'This faculty schedule has not been finalized yet. Finalize the schedule before sending it to the faculty member.',
            ]);
        }

        $rows = $this->scheduleRows($faculty, $term);

        $previous = FacultyScheduleEmail::query()
            ->where('faculty_id', $faculty->id)
            ->where('academic_term_id', $term->id)
            ->where('status', 'sent')
            ->orderByDesc('schedule_version')
            ->first();

        $snapshot = $this->buildSnapshot($rows);
        $version = $previous ? $previous->schedule_version : 1;
        $emailType = 'initial';

        if ($previous) {
            $changed = $previous->schedule_snapshot !== $snapshot;
            $version = $changed ? $previous->schedule_version + 1 : $previous->schedule_version;
            $emailType = $changed ? 'updated' : 'resend';
        }

        $record = FacultyScheduleEmail::create([
            'faculty_id' => $faculty->id,
            'academic_term_id' => $term->id,
            'sent_by' => $sender->id,
            'recipient_email' => $faculty->email,
            'schedule_version' => $version,
            'email_type' => $emailType,
            'status' => 'pending',
            'schedule_snapshot' => $snapshot,
            'pdf_filename' => $this->pdfFilename($faculty, $term),
        ]);

        SendFacultyScheduleEmailJob::dispatch($record->id);

        return $record;
    }

    /**
     * Re-send a past record as-is (spec section 11 "Resend") without
     * re-evaluating version bumping — it reuses the original snapshot.
     */
    public function resend(FacultyScheduleEmail $record, User $sender): FacultyScheduleEmail
    {
        $faculty = $record->faculty;

        if (! $faculty->email || ! filter_var($faculty->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'The email address stored for this faculty member is not valid. Please update the faculty profile.',
            ]);
        }

        $copy = FacultyScheduleEmail::create([
            'faculty_id' => $record->faculty_id,
            'academic_term_id' => $record->academic_term_id,
            'sent_by' => $sender->id,
            'recipient_email' => $faculty->email,
            'schedule_version' => $record->schedule_version,
            'email_type' => 'resend',
            'status' => 'pending',
            'schedule_snapshot' => $record->schedule_snapshot,
            'pdf_filename' => $record->pdf_filename,
        ]);

        SendFacultyScheduleEmailJob::dispatch($copy->id);

        return $copy;
    }

    /**
     * Bulk-send finalized schedules to faculty with a valid email for
     * the term (spec section 15/16). By default targets every Active
     * faculty member; pass $facultyIds to scope it to whatever the
     * Reports page currently has filtered/selected (e.g. one college,
     * or a specific multi-select of faculty) instead of the whole
     * school. Returns the counts the confirmation modal displays, and
     * queues one job per eligible faculty member.
     *
     * @param  array<int>|null  $facultyIds
     * @return array{total: int, with_email: int, missing_email: int, queued: int}
     */
    public function bulkSend(AcademicTerm $term, User $sender, ?array $facultyIds = null): array
    {
        $faculty = Faculty::query()
            ->where('status', 'Active')
            ->when($facultyIds, fn ($q) => $q->whereIn('id', $facultyIds))
            ->get();

        $missingEmail = 0;
        $queued = 0;

        foreach ($faculty as $member) {
            if (! $member->email) {
                $missingEmail++;

                continue;
            }

            if (! $this->isFinalized($member, $term)) {
                continue;
            }

            try {
                $this->send($member, $term, $sender);
                $queued++;
            } catch (ValidationException) {
                // Invalid email or not finalized — already accounted for
                // above, or skipped silently (bulk send only targets
                // finalized schedules per spec section 4).
            }
        }

        return [
            'total' => $faculty->count(),
            'with_email' => $faculty->whereNotNull('email')->count(),
            'missing_email' => $missingEmail,
            'queued' => $queued,
        ];
    }

    public function pdfFilename(Faculty $faculty, AcademicTerm $term): string
    {
        $name = str_replace(' ', '_', trim($faculty->last_name.' '.$faculty->first_name));
        $year = str_replace('/', '-', (string) $term->schoolYear?->name);
        $semester = str_replace(' ', '-', (string) $term->semester?->name);

        return "{$name}_Faculty_Schedule_{$year}_{$semester}.pdf";
    }
}