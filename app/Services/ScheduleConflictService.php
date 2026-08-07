<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\SectionSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * All Section / Faculty / Room / Time-overlap conflict-detection logic
 * for the scheduling workspace lives here — controllers never run their
 * own conflict queries.
 *
 * GLOBAL, ACTIVE-SEMESTER SCOPE
 * ------------------------------------------------------------------
 * Every check in this service is scoped to every Section that belongs
 * to the currently Active Academic Term (School Year + Semester), NOT
 * just the Section currently being edited. A Faculty member, Room, or
 * Section can never end up double-booked anywhere in the active
 * semester, regardless of which Section/Curriculum/College the other
 * placement belongs to. Placements that belong to a past/future
 * semester are intentionally ignored — they cannot conflict with a
 * schedule being built for the active one.
 *
 * Two schedules are considered overlapping when they share at least one
 * Day AND:
 *
 *     new_start < existing_end  AND  new_end > existing_start
 *
 * This single rule is applied identically for Section, Faculty, and
 * Room conflicts (see overlaps()) so the three checks can never drift
 * out of sync with each other.
 *
 * VALIDATION PRIORITY
 * ------------------------------------------------------------------
 * validate() runs every check in this fixed order, per the Registrar's
 * spec, and returns as soon as a higher-priority check fails so the
 * Registrar / Auto Generate engine always sees the single most
 * important reason first:
 *
 *   1. Faculty availability      (Faculty exists & is Active)
 *   2. Faculty schedule conflict (global, active semester)
 *   3. Room availability         (Room exists & is Active)
 *   4. Room conflict             (global, active semester)
 *   5. Section conflict          (global, active semester)
 *   6. Lunch break restriction
 *   7. Academic calendar allowed days
 *   8. Time within allowed class hours
 *   9. Subject duration fits the slot
 *
 * Only when every one of these passes does a placement qualify for a
 * 100% confidence score (see RecommendationService).
 *
 * This service is deliberately framework-thin (one Eloquent query per
 * check, no HTTP concerns) so the future Genetic Algorithm scheduler
 * can call validate() on every candidate placement before finalizing a
 * generated schedule, exactly the same way the manual workspace does.
 */
class ScheduleConflictService
{
    /**
     * Run every conflict/availability check for one schedule slot, in
     * strict priority order, and return the errors keyed the way the
     * scheduling workspace's form expects (faculty_id / room_id /
     * days). Empty array = fully valid, conflict-free placement.
     *
     * @param  array{section_id:int, faculty_id:?int, room_id:?int, days:list<string>, start_time:?string, end_time:?string, expected_minutes?:?int}  $slot
     * @return array<string, string>
     */
    public function validate(array $slot, int $excludingSectionSubjectId): array
    {
        $dayTokens = array_values(array_unique(array_filter($slot['days'] ?? [])));
        $startTime = $slot['start_time'] ?? null;
        $endTime = $slot['end_time'] ?? null;
        $facultyId = $slot['faculty_id'] ?? null;
        $roomId = $slot['room_id'] ?? null;
        $sectionId = $slot['section_id'] ?? null;

        // 1 & 3. Availability checks don't need a Day/Time window —
        // an Inactive Faculty/Room is never assignable, period.
        if ($facultyId) {
            $faculty = Faculty::find($facultyId);
            if (! $faculty) {
                return ['faculty_id' => 'Selected faculty member no longer exists.'];
            }
            if ($faculty->status !== 'Active') {
                return ['faculty_id' => "{$faculty->full_name} is not an Active faculty member and cannot be assigned."];
            }
        }

        if ($roomId) {
            $room = Room::find($roomId);
            if (! $room) {
                return ['room_id' => 'Selected room no longer exists.'];
            }
            if ($room->status !== 'Active') {
                return ['room_id' => "Room {$room->room_code} is not Active and cannot be assigned."];
            }
        }

        // Nothing further to validate until the slot has a full
        // Day/Time window.
        if (empty($dayTokens) || ! $startTime || ! $endTime) {
            return [];
        }

        // 2. Faculty schedule conflict — global, active semester.
        if ($facultyId) {
            $conflict = $this->findFacultyConflict($facultyId, $excludingSectionSubjectId, $dayTokens, $startTime, $endTime);
            if ($conflict) {
                $facultyName = $conflict->faculty?->full_name ?? 'This faculty member';

                return ['faculty_id' => "Faculty Conflict: {$facultyName} already teaches "
                    ."{$conflict->subject?->subject_code} in {$conflict->section?->section_code} on "
                    .$this->describeWindow($conflict).'.', ];
            }
        }

        // 4. Room conflict — global, active semester.
        if ($roomId) {
            $conflict = $this->findRoomConflict($roomId, $excludingSectionSubjectId, $dayTokens, $startTime, $endTime);
            if ($conflict) {
                $roomCode = $conflict->room?->room_code ?? 'selected';

                return ['room_id' => "Room Conflict: Room {$roomCode} is already occupied by "
                    ."{$conflict->subject?->subject_code} ({$conflict->section?->section_code}) on "
                    .$this->describeWindow($conflict).'.', ];
            }
        }

        // 5. Section conflict — a Section cannot have two overlapping
        // classes, regardless of Faculty/Room.
        if ($sectionId) {
            $conflict = $this->findSectionConflict($sectionId, $excludingSectionSubjectId, $dayTokens, $startTime, $endTime);
            if ($conflict) {
                return ['days' => 'Section Conflict: This section already has '
                    ."{$conflict->subject?->subject_code} scheduled on ".$this->describeWindow($conflict).'.', ];
            }
        }

        $activeSchoolYear = SchoolYear::active();

        // 6. Lunch break restriction (fixed, non-editable window).
        if (SchoolYear::overlapsLunchBreak($startTime, $endTime)) {
            return ['days' => 'This time slot overlaps the Lunch Break (12:00 PM - 1:00 PM).'];
        }

        // 7. Academic calendar allowed days — every Day requested must
        // be one of the active School Year's configured Class Days.
        if ($activeSchoolYear) {
            foreach ($dayTokens as $day) {
                if (! $activeSchoolYear->isDayAllowed($day)) {
                    return ['days' => "{$day} is not an allowed class day for the active academic calendar."];
                }
            }
        }

        // 8. Time within allowed class hours (Class Start/End Time).
        if ($activeSchoolYear && ! $activeSchoolYear->isWithinSchedulingPolicy($startTime, $endTime)) {
            return ['days' => 'This time falls outside the allowed class hours ('
                .$activeSchoolYear->classStartTime().' - '.$activeSchoolYear->classEndTime().').', ];
        }

        // 9. Subject duration — the slot must actually fit the
        // Subject's required contact minutes for this meeting, when
        // the caller supplied one to check against.
        if (! empty($slot['expected_minutes'])) {
            $actualMinutes = $this->minutesBetween($startTime, $endTime);
            if ($actualMinutes + 5 < $slot['expected_minutes']) {
                return ['days' => "This time slot is shorter than the subject's required meeting duration."];
            }
        }

        return [];
    }

    /**
     * A Section cannot have two overlapping classes, regardless of
     * which Faculty or Room is assigned to either one. Scoped to the
     * active semester.
     */
    public function findSectionConflict(
        int $sectionId,
        int $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime
    ): ?SectionSubject {
        return $this->findOverlap(
            SectionSubject::query()->where('section_id', $sectionId),
            $excludingId,
            $dayTokens,
            $startTime,
            $endTime
        );
    }

    /**
     * A Faculty member cannot teach two overlapping classes, across
     * ANY Section, College, or Curriculum — scoped to every Section
     * in the active semester.
     */
    public function findFacultyConflict(
        int $facultyId,
        int $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime
    ): ?SectionSubject {
        return $this->findOverlap(
            SectionSubject::query()
                ->where('faculty_id', $facultyId)
                ->whereIn('section_id', $this->activeSemesterSectionIds()),
            $excludingId,
            $dayTokens,
            $startTime,
            $endTime
        );
    }

    /**
     * A Room cannot host two overlapping classes, across ANY Section
     * or College — scoped to every Section in the active semester.
     * Room is irrelevant to Faculty conflicts and vice versa: each
     * check only ever compares like-for-like (Room vs Room, Faculty
     * vs Faculty).
     */
    public function findRoomConflict(
        int $roomId,
        int $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime
    ): ?SectionSubject {
        return $this->findOverlap(
            SectionSubject::query()
                ->where('room_id', $roomId)
                ->whereIn('section_id', $this->activeSemesterSectionIds()),
            $excludingId,
            $dayTokens,
            $startTime,
            $endTime
        );
    }

    /**
     * Every Section belonging to the currently Active Academic Term
     * (School Year + Semester), via AcademicTerm::matchingSectionsQuery()
     * — never a raw string compare of the Semester name (see that
     * method's docblock for why: Sections and the Semester model spell
     * the same Semester differently, and an exact compare silently
     * matches nothing).
     *
     * When there is no Active Academic Term configured, this
     * deliberately falls back to "every Section" rather than "no
     * Sections", so conflict detection never silently turns itself
     * off just because nobody has marked a term Active yet.
     */
    public function activeSemesterSectionIds(): Collection
    {
        $activeTerm = AcademicTerm::active();

        $query = $activeTerm ? $activeTerm->matchingSectionsQuery() : Section::query();

        return $query->pluck('id');
    }

    /**
     * Time-overlap rule shared by every conflict type:
     *
     *     new_start < existing_end  AND  new_end > existing_start
     *
     * Exposed publicly so the frontend's real-time check and any future
     * scheduler can both cite the exact same rule when documenting or
     * mirroring this logic client-side.
     */
    public function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $endA > $startB;
    }

    /**
     * Whether two Day lists share at least one common Day token.
     */
    public function sharesDay(array $daysA, array $daysB): bool
    {
        return ! empty(array_intersect($daysA, $daysB));
    }

    /**
     * Finds another SectionSubject row (excluding the one being
     * edited) already booked on any of the given Days whose
     * Start/End Time overlaps the given window, within an
     * already-scoped query (by section_id, faculty_id, or room_id,
     * plus — for Faculty/Room — the active semester's Section ids).
     */
    private function findOverlap(
        Builder $query,
        int $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime
    ): ?SectionSubject {
        return $query->with(['subject:id,subject_code', 'section:id,section_code', 'faculty:id,first_name,last_name', 'room:id,room_code'])
            ->where('id', '!=', $excludingId)
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->where(function ($q) use ($dayTokens) {
                foreach ($dayTokens as $day) {
                    // Days are stored as a comma-separated string of
                    // exact tokens (Mon/Tue/Wed/Thu/Fri/Sat) — every
                    // token is a distinct 3-letter code, so a plain
                    // substring LIKE can never false-match a different
                    // Day (e.g. "Tue" is never a substring of any
                    // other token).
                    $q->orWhere('days', 'like', "%{$day}%");
                }
            })
            // Mirrors overlaps() as a SQL predicate — kept in sync by hand
            // since the query needs it inline, not as a callable.
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->first();
    }

    private function describeWindow(SectionSubject $conflict): string
    {
        return trim("{$conflict->days} {$conflict->start_time}-{$conflict->end_time}", ' -');
    }

    private function minutesBetween(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));

        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }
}