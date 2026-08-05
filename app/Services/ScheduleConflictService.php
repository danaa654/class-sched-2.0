<?php

namespace App\Services;

use App\Models\SectionSubject;
use Illuminate\Database\Eloquent\Builder;

/**
 * All Section / Faculty / Room / Time-overlap conflict-detection logic
 * for the scheduling workspace lives here — controllers never run their
 * own conflict queries.
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
 * This service is deliberately framework-thin (one Eloquent query per
 * check, no HTTP concerns) so the future Genetic Algorithm scheduler
 * can call validate() on every candidate placement before finalizing a
 * generated schedule, exactly the same way the manual workspace does.
 */
class ScheduleConflictService
{
    /**
     * Run every conflict check for one schedule slot and return the
     * errors keyed the way the scheduling workspace's form expects
     * (faculty_id / room_id / days). Empty array = no conflicts.
     *
     * @param  array{section_id:int, faculty_id:?int, room_id:?int, days:list<string>, start_time:?string, end_time:?string}  $slot
     * @return array<string, string>
     */
    public function validate(array $slot, int $excludingSectionSubjectId): array
    {
        $dayTokens = array_values(array_filter($slot['days'] ?? []));
        $startTime = $slot['start_time'] ?? null;
        $endTime = $slot['end_time'] ?? null;

        // Nothing to validate until the slot has a full Day/Time window.
        if (empty($dayTokens) || ! $startTime || ! $endTime) {
            return [];
        }

        $errors = [];

        if (! empty($slot['section_id'])) {
            $conflict = $this->findSectionConflict($slot['section_id'], $excludingSectionSubjectId, $dayTokens, $startTime, $endTime);
            if ($conflict) {
                $errors['days'] = 'This section already has an overlapping class at this day and time ('
                    ."{$conflict->subject?->subject_code} — {$this->describeWindow($conflict)}).";
            }
        }

        if (! empty($slot['faculty_id'])) {
            $conflict = $this->findFacultyConflict($slot['faculty_id'], $excludingSectionSubjectId, $dayTokens, $startTime, $endTime);
            if ($conflict) {
                $errors['faculty_id'] = 'This faculty member already has a class at this day and time ('
                    ."{$conflict->subject?->subject_code} — {$conflict->section?->section_code}).";
            }
        }

        if (! empty($slot['room_id'])) {
            $conflict = $this->findRoomConflict($slot['room_id'], $excludingSectionSubjectId, $dayTokens, $startTime, $endTime);
            if ($conflict) {
                $errors['room_id'] = 'This room is already booked at this day and time ('
                    ."{$conflict->subject?->subject_code} — {$conflict->section?->section_code}).";
            }
        }

        return $errors;
    }

    /**
     * A Section cannot have two overlapping classes, regardless of
     * which Faculty or Room is assigned to either one.
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
     * any Section.
     */
    public function findFacultyConflict(
        int $facultyId,
        int $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime
    ): ?SectionSubject {
        return $this->findOverlap(
            SectionSubject::query()->where('faculty_id', $facultyId),
            $excludingId,
            $dayTokens,
            $startTime,
            $endTime
        );
    }

    /**
     * A Room cannot host two overlapping classes, across any Section.
     */
    public function findRoomConflict(
        int $roomId,
        int $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime
    ): ?SectionSubject {
        return $this->findOverlap(
            SectionSubject::query()->where('room_id', $roomId),
            $excludingId,
            $dayTokens,
            $startTime,
            $endTime
        );
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
     * Finds another SectionSubject row (excluding the one being
     * edited) already booked on any of the given Days whose
     * Start/End Time overlaps the given window, within an
     * already-scoped query (by section_id, faculty_id, or room_id).
     */
    private function findOverlap(
        Builder $query,
        int $excludingId,
        array $dayTokens,
        string $startTime,
        string $endTime
    ): ?SectionSubject {
        return $query->with(['subject:id,subject_code', 'section:id,section_code'])
            ->where('id', '!=', $excludingId)
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->where(function ($q) use ($dayTokens) {
                foreach ($dayTokens as $day) {
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
}