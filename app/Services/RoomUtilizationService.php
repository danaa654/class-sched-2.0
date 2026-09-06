<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Room;
use App\Models\SchoolYear;
use App\Models\SectionSubject;
use Illuminate\Support\Collection;

/**
 * The single source of truth for Room Utilization, Availability, and
 * Room-level Schedule Conflicts.
 *
 * "Utilization" is never a stored/cached number — it is always
 * recomputed from the live SectionSubject rows that belong to the
 * currently Active Academic Term (School Year + Semester), against the
 * Active School Year's scheduling policy (Class Days, Class Start/End
 * Time, 30-minute interval, fixed 12:00-1:00 PM Lunch Break). This
 * keeps the Rooms page and the Auto Scheduler's room ranking
 * (RecommendationService) reading exactly the same numbers, and means
 * utilization can never go stale: it recalculates itself the instant a
 * schedule is generated, edited, deleted, or reassigned, because there
 * is nothing to invalidate.
 *
 * Maximum Available Room Hours is derived dynamically from the Active
 * School Year's configured days/hours/interval, NOT a hardcoded 40.
 */
class RoomUtilizationService
{
    public function __construct(private readonly ScheduleConflictService $conflicts) {}

    /**
     * Deletion-impact summary for one Room — powers the double
     * confirmation on Room delete (spec: same pattern as
     * FacultyWorkloadService::deactivationImpact()).
     *
     * A Room tied to a finalized/locked Section can never be deleted
     * outright (the schedule must be unlocked and the class moved
     * first). A Room with active (non-finalized) placements may still
     * be deleted, but only after the admin/registrar confirms —
     * deleting it leaves those SectionSubject rows without a Room.
     *
     * @return array{
     *   has_active_assignments:bool,
     *   has_finalized_assignment:bool,
     *   subject_count:int,
     *   section_count:int,
     *   subject_codes:array<int,string>,
     *   section_codes:array<int,string>,
     *   finalized_section_codes:array<int,string>,
     * }
     */
    public function deletionImpact(Room $room): array
    {
        $placements = $this->activeTermPlacementsForRoom($room->id, ['section', 'subject']);

        $finalized = $placements->filter(fn (SectionSubject $p) => (bool) ($p->section?->is_finalized));
        $active = $placements->reject(fn (SectionSubject $p) => (bool) ($p->section?->is_finalized));

        return [
            'has_active_assignments' => $active->isNotEmpty(),
            'has_finalized_assignment' => $finalized->isNotEmpty(),
            'subject_count' => $active->pluck('subject_id')->unique()->count(),
            'section_count' => $active->pluck('section_id')->unique()->count(),
            'subject_codes' => $active->pluck('subject.subject_code')->filter()->unique()->values()->all(),
            'section_codes' => $active->pluck('section.section_code')->filter()->unique()->values()->all(),
            'finalized_section_codes' => $finalized->pluck('section.section_code')->filter()->unique()->values()->all(),
        ];
    }

    /**
     * Weekly Utilization + Availability summary for one Room, scoped to
     * the Active Academic Term.
     *
     * @return array{
     *   room_id:int,
     *   scheduled_hours:float,
     *   max_hours:float,
     *   remaining_hours:float,
     *   utilization_percent:float,
     *   utilization_status:string,
     *   availability:string,
     *   has_conflict:bool,
     *   by_day:array<string,array{scheduled_hours:float,max_hours:float,utilization_percent:float}>,
     * }
     */
    public function summarizeRoom(Room $room, ?Collection $placements = null, ?SchoolYear $schoolYear = null): array
    {
        $schoolYear ??= SchoolYear::active();
        $placements ??= $this->activeTermPlacementsForRoom($room->id);

        $days = $schoolYear ? $schoolYear->allowedDays() : SchoolYear::DEFAULT_CLASS_DAYS;
        $maxHoursPerDay = $this->maxHoursPerDay($schoolYear);
        $maxHours = round($maxHoursPerDay * count($days), 2);

        $byDay = [];
        $scheduledHours = 0.0;
        $hasConflict = false;

        foreach ($days as $day) {
            $dayMinutes = 0;
            foreach ($placements as $placement) {
                if (! $this->placementRunsOnDay($placement, $day)) {
                    continue;
                }
                $dayMinutes += $this->minutesBetween($placement->start_time, $placement->end_time);
            }

            $dayHours = round($dayMinutes / 60, 2);
            $scheduledHours += $dayHours;

            $byDay[$day] = [
                'scheduled_hours' => $dayHours,
                'max_hours' => $maxHoursPerDay,
                'utilization_percent' => $maxHoursPerDay > 0
                    ? round(min(999, ($dayHours / $maxHoursPerDay) * 100), 1)
                    : 0.0,
            ];

            if ($dayHours > $maxHoursPerDay + 0.01) {
                $hasConflict = true;
            }
        }

        // A genuine room double-booking (two placements overlapping in
        // time on the same day) is always a conflict, independent of
        // whether it pushed total hours past the daily maximum.
        $hasConflict = $hasConflict || $this->hasOverlap($placements);

        $utilizationPercent = $maxHours > 0 ? round(($scheduledHours / $maxHours) * 100, 1) : 0.0;

        // CAPACITY AWARENESS — the largest enrollment currently
        // assigned to this room this term, compared against the
        // Room's own capacity. Mirrors the same "Section Capacity >
        // Room Capacity" rule SectionSubjectController already
        // enforces (with Registrar override) at save time; here it's
        // surfaced passively so the Rooms page can flag it without
        // re-deriving the rule.
        $peakEnrollment = $placements->max(fn (SectionSubject $p) => (int) ($p->capacity ?? 0)) ?? 0;
        $capacityExceeded = $peakEnrollment > $room->capacity;

        return [
            'room_id' => $room->id,
            'scheduled_hours' => round($scheduledHours, 2),
            'max_hours' => $maxHours,
            'remaining_hours' => max(0, round($maxHours - $scheduledHours, 2)),
            'utilization_percent' => $utilizationPercent,
            'utilization_status' => $this->utilizationStatus($utilizationPercent),
            'availability' => $this->availabilityStatus($room, $utilizationPercent, $hasConflict || $capacityExceeded),
            'has_conflict' => $hasConflict || $capacityExceeded,
            'capacity' => $room->capacity,
            'peak_enrollment' => $peakEnrollment,
            'capacity_exceeded' => $capacityExceeded,
            'seats_available' => max(0, $room->capacity - $peakEnrollment),
            'by_day' => $byDay,
        ];
    }

    /**
     * Summaries for every given Room in one pass — one query for all
     * placements instead of N+1 per room. Keyed by room_id.
     *
     * @param  \Illuminate\Support\Collection<int, Room>  $rooms
     * @return array<int, array>
     */
    public function summarizeRooms(Collection $rooms): array
    {
        $schoolYear = SchoolYear::active();
        $roomIds = $rooms->pluck('id')->all();

        $placementsByRoom = $this->activeTermPlacements()
            ->whereIn('room_id', $roomIds)
            ->get()
            ->groupBy('room_id');

        return $rooms->mapWithKeys(function (Room $room) use ($placementsByRoom, $schoolYear) {
            $placements = $placementsByRoom->get($room->id, collect());

            return [$room->id => $this->summarizeRoom($room, $placements, $schoolYear)];
        })->all();
    }

    /**
     * The Room's weekly timetable, grouped by Day, for the "Room
     * Schedule Details" modal — plus the empty slots for that day so
     * the modal can show open capacity alongside booked classes.
     *
     * @return array<string, array{
     *   booked: list<array{start_time:string,end_time:string,subject:string,section:string,faculty:string,section_subject_id:int}>,
     *   available: list<array{start_time:string,end_time:string}>,
     * }>
     */
    public function timetableForRoom(Room $room): array
    {
        $schoolYear = SchoolYear::active();
        $days = $schoolYear ? $schoolYear->allowedDays() : SchoolYear::DEFAULT_CLASS_DAYS;
        $placements = $this->activeTermPlacementsForRoom($room->id, [
            'subject:id,subject_code,subject_title',
            'section:id,section_code',
            'faculty:id,first_name,last_name',
            // Every Irregular-section row merged into this one — same
            // class session, riding along on the exact same booking.
            // Folded into `section` below so the modal shows every
            // Section actually sitting in that slot instead of
            // silently hiding the merged one.
            'mergedPlacements.section:id,section_code',
        ]);

        $timetable = [];

        foreach ($days as $day) {
            $dayPlacements = $placements
                ->filter(fn (SectionSubject $p) => $this->placementRunsOnDay($p, $day))
                ->sortBy('start_time')
                ->values();

            $booked = $dayPlacements->map(function (SectionSubject $p) {
                $sectionCodes = collect([$p->section?->section_code])
                    ->merge($p->mergedPlacements->pluck('section.section_code'))
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'section_subject_id' => $p->id,
                    'start_time' => substr($p->start_time, 0, 5),
                    'end_time' => substr($p->end_time, 0, 5),
                    'subject' => $p->subject?->subject_code ?? '—',
                    'section' => $sectionCodes->isNotEmpty() ? $sectionCodes->implode(' & ') : '—',
                    'faculty' => $p->faculty?->full_name ?? 'Unassigned',
                ];
            })->all();

            $timetable[$day] = [
                'booked' => $booked,
                'available' => $this->freeGapsForDay($dayPlacements, $schoolYear),
            ];
        }

        return $timetable;
    }

    /**
     * Bucket a Utilization % into the four indicator tiers used
     * throughout the Rooms page and Auto Scheduler ranking.
     */
    public function utilizationStatus(float $percent): string
    {
        return match (true) {
            $percent > 100 => 'Overbooked',
            $percent >= 91 => 'Nearly Full',
            $percent >= 71 => 'High Usage',
            default => 'Normal',
        };
    }

    /**
     * Every SectionSubject placement in Rooms, in the Active Academic
     * Term, that is actually scheduled (has Days/Start/End Time) —
     * this is the same "does this row count as a live schedule" test
     * ScheduleConflictService uses.
     *
     * INTELLIGENT IRREGULAR SECTION SCHEDULING — a merged Irregular-
     * section row (`merged_into_section_subject_id` set) occupies the
     * exact same Room/Day/Time as its host row by design — it's the
     * same class session, just ridden along on by another Section,
     * never a second booking. Counting both here double-charges the
     * Room's scheduled hours and makes hasOverlap() see two rows
     * sharing one slot and misreport it as a Room conflict /
     * "Overbooked", even though nothing is actually double-booked.
     * Excluded here the same way FacultyWorkloadService::
     * activePlacements() excludes them from Faculty load.
     */
    private function activeTermPlacements(array $with = []): \Illuminate\Database\Eloquent\Builder
    {
        return SectionSubject::query()
            ->whereNotNull('room_id')
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereNull('merged_into_section_subject_id')
            ->whereIn('section_id', $this->conflicts->activeSemesterSectionIds())
            ->with($with);
    }

    public function activeTermPlacementsForRoom(int $roomId, array $with = []): Collection
    {
        return $this->activeTermPlacements($with)->where('room_id', $roomId)->get();
    }

    /**
     * Maximum schedulable hours in a single day under the Active
     * School Year's policy: (Class End - Class Start) minus the fixed
     * Lunch Break, whenever the Lunch window actually falls inside the
     * class hours for that day.
     */
    private function maxHoursPerDay(?SchoolYear $schoolYear): float
    {
        $start = $schoolYear?->classStartTime() ?? SchoolYear::DEFAULT_CLASS_START_TIME;
        $end = $schoolYear?->classEndTime() ?? SchoolYear::DEFAULT_CLASS_END_TIME;

        $totalMinutes = $this->minutesBetween($start, $end);

        if (SchoolYear::overlapsLunchBreak($start, $end)) {
            $lunchStart = max($start, SchoolYear::LUNCH_BREAK_START);
            $lunchEnd = min($end, SchoolYear::LUNCH_BREAK_END);
            $totalMinutes -= max(0, $this->minutesBetween($lunchStart, $lunchEnd));
        }

        return round(max(0, $totalMinutes) / 60, 2);
    }

    private function placementRunsOnDay(SectionSubject $placement, string $day): bool
    {
        $tokens = array_filter(array_map('trim', explode(',', (string) $placement->days)));

        return in_array($day, $tokens, true);
    }

    private function hasOverlap(Collection $placements): bool
    {
        $list = $placements->values();

        for ($i = 0; $i < $list->count(); $i++) {
            for ($j = $i + 1; $j < $list->count(); $j++) {
                $a = $list[$i];
                $b = $list[$j];

                if (! $this->conflicts->sharesDay(
                    array_filter(array_map('trim', explode(',', (string) $a->days))),
                    array_filter(array_map('trim', explode(',', (string) $b->days)))
                )) {
                    continue;
                }

                if ($this->conflicts->overlaps($a->start_time, $a->end_time, $b->start_time, $b->end_time)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Room availability status — always derived from actual schedules
     * (never just the Room's Active/Inactive flag).
     */
    private function availabilityStatus(Room $room, float $utilizationPercent, bool $hasConflict): string
    {
        if ($room->status !== 'Active') {
            return 'Inactive';
        }

        if ($hasConflict || $utilizationPercent > 100) {
            return 'Overbooked';
        }

        if ($utilizationPercent >= 100) {
            return 'Fully Booked';
        }

        if ($utilizationPercent >= 71) {
            return 'Partially Available';
        }

        return 'Available';
    }

    /**
     * The open (unbooked) windows for one Day, between Class Start and
     * Class End Time, minus the fixed Lunch Break and every booked
     * placement — sliced at the School Year's configured interval so
     * gaps line up with the same grid the Auto Scheduler walks.
     *
     * @param  \Illuminate\Support\Collection<int, SectionSubject>  $dayPlacements
     * @return list<array{start_time:string,end_time:string}>
     */
    private function freeGapsForDay(Collection $dayPlacements, ?SchoolYear $schoolYear): array
    {
        $start = $schoolYear?->classStartTime() ?? SchoolYear::DEFAULT_CLASS_START_TIME;
        $end = $schoolYear?->classEndTime() ?? SchoolYear::DEFAULT_CLASS_END_TIME;

        // Lunch Break restriction removed per adviser direction — no
        // longer added as a blocked range.
        $blocked = $dayPlacements->map(fn (SectionSubject $p) => [substr($p->start_time, 0, 5), substr($p->end_time, 0, 5)])->all();

        usort($blocked, fn ($a, $b) => $a[0] <=> $b[0]);

        $gaps = [];
        $cursor = $start;

        foreach ($blocked as [$blockStart, $blockEnd]) {
            if ($blockStart > $cursor) {
                $gaps[] = ['start_time' => $cursor, 'end_time' => min($blockStart, $end)];
            }
            $cursor = max($cursor, $blockEnd);
        }

        if ($cursor < $end) {
            $gaps[] = ['start_time' => $cursor, 'end_time' => $end];
        }

        // Drop zero/negative-length or out-of-range slivers.
        return array_values(array_filter($gaps, fn ($gap) => $gap['start_time'] < $gap['end_time'] && $gap['start_time'] >= $start && $gap['end_time'] <= $end));
    }

    private function minutesBetween(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', substr($start, 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr($end, 0, 5)));

        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }
}