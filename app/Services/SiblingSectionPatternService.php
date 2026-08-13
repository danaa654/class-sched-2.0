<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use Illuminate\Support\Facades\Log;

/**
 * SIBLING SECTION PATTERN MATCHING.
 *
 * When a Subject is already scheduled for one "sibling" Section of the
 * same cohort (same Major, Curriculum, Academic Year, Semester, and
 * Year Level — e.g. BSIT-4A/4B/4C/4D), this service tries to copy that
 * exact assignment onto another sibling Section that still needs it,
 * changing only the Day(s).
 *
 * This is a SHORTCUT that runs BEFORE the general-purpose
 * RecommendationService ranking, for two reasons the Registrar's
 * workflow depends on:
 *
 *   1. Faculty/Room CONSISTENCY across a cohort's sections — if 4A's
 *      Capstone is taught by Prof. Polo in Room 306, the Registrar
 *      overwhelmingly wants 4B/4C/4D taught by the same person in the
 *      same room too, not whichever candidate happens to rank highest
 *      in the general engine.
 *
 *   2. DURATION FIDELITY — the general engine (via
 *      MeetingPatternService + Subject::lecture_hours/laboratory_hours)
 *      always computes session length from the Subject's *declared*
 *      hours. But the Registrar may have manually trimmed a sibling's
 *      session shorter than that (e.g. a 5-hr/week Subject actually
 *      saved as a 4-hr block because of Faculty/Room availability).
 *      When copying a pattern, this service reuses the sibling's
 *      ACTUAL SAVED duration, not the Subject's textbook hours, so
 *      every sibling section ends up with the same real-world block
 *      length instead of silently reverting to the "official" one.
 *
 * If no sibling pattern can be copied (no donor exists, or the
 * donor's Faculty/Room has no free Day left for this Section), this
 * service returns null and the caller (AutoScheduleService /
 * RecommendationService) falls through to the normal ranked search
 * completely unaffected — this is a pure fast-path, never a
 * replacement for the general engine.
 */
class SiblingSectionPatternService
{
    /**
     * Diagnostic trail for the MOST RECENT findPattern() call — every
     * donor considered, every Day candidate tried against it, and
     * exactly why each one was accepted or rejected (Section, Faculty,
     * or Room conflict; no alternate Day combination available; etc).
     *
     * Populated fresh on every findPattern() call and readable via
     * getDiagnostics() immediately after — this is what lets you
     * answer "why didn't 4B copy 4A's IAS102 assignment?" without
     * guessing. Also mirrored to the log (see LOG_CHANNEL) at debug
     * level so it's inspectable even when the caller never reads
     * getDiagnostics() (e.g. during bulk Auto Generate runs).
     *
     * @var list<array<string, mixed>>
     */
    private array $diagnostics = [];

    /**
     * Log channel/level tag prefix used for every trace line this
     * service writes. Search storage/logs/laravel.log for this string
     * to find every Sibling Pattern decision made during a request.
     */
    private const LOG_TAG = '[SiblingPattern]';

    public function __construct(
        private readonly ScheduleConflictService $conflictService,
        private readonly MeetingPatternService $meetingPatternService,
    ) {
    }

    /**
     * The full diagnostic trail from the most recent findPattern()
     * call — one entry per donor considered, each with a `days_tried`
     * breakdown showing exactly which conflict (if any) rejected each
     * candidate Day combination.
     *
     * Call this immediately after findPattern() to inspect why it
     * returned what it did:
     *
     *   $pattern = $siblingPatternService->findPattern($sectionSubject);
     *   dd($siblingPatternService->getDiagnostics());
     *
     * @return list<array<string, mixed>>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Try to build a copied Faculty + Room + Days + Start/End Time
     * assignment for $sectionSubject by finding a sibling Section
     * that already has this exact Subject fully scheduled.
     *
     * Returns null if no usable donor pattern exists (caller should
     * fall back to RecommendationService as normal).
     *
     * @return array{faculty_id:int, room_id:int, days:list<string>, start_time:string, end_time:string, donor_section_id:int, donor_section_code:string}|null
     */
    public function findPattern(SectionSubject $sectionSubject): ?array
    {
        $this->diagnostics = [];

        $section = $sectionSubject->section;
        $subject = $sectionSubject->subject;

        if (! $section || ! $subject) {
            $this->log('No pattern search performed — Section or Subject missing on the row.', [
                'section_subject_id' => $sectionSubject->id,
            ]);

            return null;
        }

        $donors = $this->donorRows($section, $subject, $sectionSubject->id);

        if ($donors->isEmpty()) {
            $this->log('No donor found.', [
                'target_section' => $section->section_code,
                'subject' => $subject->subject_code,
                'reason' => 'No sibling Section (same Major/Curriculum/Academic Year/Semester/Year Level) has this Subject fully scheduled yet.',
            ]);

            return null;
        }

        $excludingId = $sectionSubject->id ?? 0;

        // Multiple donors (4A AND 4C already scheduled) — try each in
        // order until one still has a free Day for this Section's
        // exact Faculty+Room+duration. Never just take the first
        // donor blindly if its Faculty/Room is fully booked.
        foreach ($donors as $donor) {
            $pattern = $this->tryDonor($section, $subject, $donor, $excludingId);

            if ($pattern) {
                $this->log('Pattern copied.', [
                    'target_section' => $section->section_code,
                    'subject' => $subject->subject_code,
                    'donor_section' => $pattern['donor_section_code'],
                    'faculty_id' => $pattern['faculty_id'],
                    'room_id' => $pattern['room_id'],
                    'days' => $pattern['days'],
                    'start_time' => $pattern['start_time'],
                    'end_time' => $pattern['end_time'],
                ]);

                return $pattern;
            }
        }

        $this->log('No donor could be copied — every candidate had a conflict on every available day. Falling back to normal recommendation engine.', [
            'target_section' => $section->section_code,
            'subject' => $subject->subject_code,
            'donors_tried' => $donors->pluck('section.section_code')->values()->all(),
        ]);

        return null;
    }

    /**
     * Every OTHER Section in the same cohort (same Major, Curriculum,
     * Academic Year, Semester, Year Level) that already has a
     * COMPLETE saved schedule for this exact Subject. "Complete"
     * means Faculty, Room, Days, Start Time, and End Time are all
     * set — a half-filled or auto-generated-but-unresolved sibling
     * row is never used as a donor.
     */
    private function donorRows(Section $section, Subject $subject, int $excludingSectionSubjectId)
    {
        return SectionSubject::query()
            ->where('subject_id', $subject->id)
            ->where('id', '!=', $excludingSectionSubjectId)
            ->whereNotNull('faculty_id')
            ->whereNotNull('room_id')
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereHas('section', function ($query) use ($section) {
                $query->where('id', '!=', $section->id)
                    ->where('major_id', $section->major_id)
                    ->where('curriculum_id', $section->curriculum_id)
                    ->where('academic_year', $section->academic_year)
                    ->where('semester', $section->semester)
                    ->where('year_level', $section->year_level);
            })
            ->with('section')
            ->get()
            // Prefer donors whose Section Code sorts first (4A before
            // 4C) purely for deterministic, predictable behavior when
            // several equally-valid donors exist.
            ->sortBy(fn (SectionSubject $row) => $row->section->section_code ?? '')
            ->values();
    }

    /**
     * Attempt to reuse one donor's Faculty, Room, and duration for
     * $section, searching for a Day combination that:
     *   - has the SAME meeting count as the donor (so a 2x/week
     *     Lecture stays 2x/week, a 1x/week Lab stays 1x/week),
     *   - is NOT the exact same Day(s) as the donor (same Faculty +
     *     Room + Day + Time as another Section would just be a
     *     conflict, not a copy),
     *   - is fully conflict-free for Section, Faculty, and Room using
     *     the donor's copied duration.
     */
    private function tryDonor(Section $section, Subject $subject, SectionSubject $donor, int $excludingId): ?array
    {
        $donorSectionCode = $donor->section->section_code ?? "section #{$donor->section_id}";

        $faculty = Faculty::find($donor->faculty_id);
        $room = Room::find($donor->room_id);

        if (! $faculty || ! $room) {
            $this->recordDonor($donorSectionCode, null, null, [], 'Donor row references a Faculty or Room that no longer exists.');

            return null;
        }

        $donorDays = $this->parseDays($donor->days);
        $sessionMinutes = $this->minutesBetween($donor->start_time, $donor->end_time);

        if (empty($donorDays) || $sessionMinutes <= 0) {
            $this->recordDonor($donorSectionCode, $faculty, $room, [], 'Donor row has an invalid or empty Days/Time — nothing to copy.');

            return null;
        }

        $meetingCount = count($donorDays);

        // Candidate Day combinations of the SAME meeting count,
        // ranked in the Registrar's preferred order — reuses the
        // exact same pattern table MeetingPatternService/
        // RecommendationService already use, so a copied pattern
        // never suggests a Day combo the rest of the engine wouldn't
        // also consider valid.
        $dayCandidates = array_values(array_filter(
            $this->meetingPatternService->dayGroupsForCount($meetingCount),
            fn (array $days) => $days !== $donorDays
        ));

        // If MeetingPatternService's own pattern table doesn't offer
        // an alternate of the same meeting count (e.g. a 1-meeting
        // Subject where every single Day is already the donor's
        // Day), there's nothing safe to copy onto — fall through.
        if (empty($dayCandidates)) {
            $this->recordDonor($donorSectionCode, $faculty, $room, [], 'No alternate Day combination of the same meeting count (' . $meetingCount . '/week) exists besides the donor\'s own Day(s) — nothing left to try.');

            return null;
        }

        $daysTried = [];

        foreach ($dayCandidates as $days) {
            [$start, $end] = $this->deriveWindow($donor->start_time, $sessionMinutes);

            if (! $start) {
                $daysTried[] = ['days' => $days, 'result' => 'skipped', 'reason' => 'Could not derive a Start/End Time window.'];

                continue;
            }

            $conflictType = null;

            if ($section->id && $this->conflictService->findSectionConflict($section->id, $excludingId, $days, $start, $end)) {
                $conflictType = 'Section';
            } elseif ($this->conflictService->findFacultyConflict($faculty->id, $excludingId, $days, $start, $end)) {
                $conflictType = 'Faculty';
            } elseif ($this->conflictService->findRoomConflict($room->id, $excludingId, $days, $start, $end)) {
                $conflictType = 'Room';
            }

            if ($conflictType !== null) {
                $daysTried[] = [
                    'days' => $days,
                    'start_time' => $start,
                    'end_time' => $end,
                    'result' => 'rejected',
                    'reason' => "{$conflictType} conflict — {$faculty->full_name} / {$room->room_name} is already booked on " . implode('/', $days) . " at {$start}-{$end}.",
                ];

                continue;
            }

            $daysTried[] = [
                'days' => $days,
                'start_time' => $start,
                'end_time' => $end,
                'result' => 'accepted',
                'reason' => 'Conflict-free for Section, Faculty, and Room.',
            ];

            $this->recordDonor($donorSectionCode, $faculty, $room, $daysTried, 'Accepted — see days_tried for the winning candidate.');

            return [
                'faculty_id' => $faculty->id,
                'room_id' => $room->id,
                'days' => $days,
                'start_time' => $start,
                'end_time' => $end,
                'donor_section_id' => $donor->section_id,
                'donor_section_code' => $donorSectionCode,
            ];
        }

        $this->recordDonor($donorSectionCode, $faculty, $room, $daysTried, 'Every candidate Day for this Faculty+Room combination had a conflict — this donor could not be used.');

        return null;
    }

    /**
     * Appends one donor's full trace (every Day tried, and why) to
     * $this->diagnostics, and mirrors it to the log immediately so
     * the trail is visible even without calling getDiagnostics().
     *
     * @param  list<array<string, mixed>>  $daysTried
     */
    private function recordDonor(string $donorSectionCode, ?Faculty $faculty, ?Room $room, array $daysTried, string $outcome): void
    {
        $entry = [
            'donor_section' => $donorSectionCode,
            'faculty' => $faculty?->full_name,
            'room' => $room?->room_name,
            'days_tried' => $daysTried,
            'outcome' => $outcome,
        ];

        $this->diagnostics[] = $entry;

        $this->log("Donor {$donorSectionCode} — {$outcome}", $entry);
    }

    /**
     * Writes one line to the standard Laravel log (debug level) with
     * a consistent, greppable tag. Never throws — a logging failure
     * should never break Auto Generate / Recommend.
     */
    private function log(string $message, array $context = []): void
    {
        try {
            Log::debug(self::LOG_TAG.' '.$message, $context);
        } catch (\Throwable $e) {
            // Logging must never break the scheduling flow.
        }
    }

    /**
     * Keep the donor's exact Start Time (and therefore End Time, via
     * the copied duration) — this is deliberate: if 4A's Capstone
     * meets 1:00 PM–5:00 PM, 4B's copy should meet at the SAME time
     * of day on its different Day, so the whole cohort keeps a
     * predictable, aligned schedule rather than drifting to whatever
     * slot happens to be free.
     *
     * @return array{0: string|null, 1: string}
     */
    private function deriveWindow(string $donorStart, int $sessionMinutes): array
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($donorStart, 0, 5)));
        $startMinutes = $hour * 60 + $minute;
        $endMinutes = $startMinutes + $sessionMinutes;

        $start = sprintf('%02d:%02d', intdiv($startMinutes, 60), $startMinutes % 60);
        $end = sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60);

        return [$start, $end];
    }

    private function minutesBetween(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', substr($start, 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr($end, 0, 5)));

        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }

    /**
     * @return list<string>
     */
    private function parseDays(?string $days): array
    {
        if (! $days) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $days))));
    }
}