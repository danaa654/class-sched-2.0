<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * DASHBOARD DATA — single source of truth for every widget on the
 * Scheduling Control Center (see DashboardController).
 *
 * ROLE-BASED SCOPE
 * ------------------------------------------------------------------
 * Every number this service returns is filtered through scopeFor():
 *
 *   - Administrator / Registrar -> institution-wide, every College.
 *   - Dean / OIC                -> only the user's own College
 *     (User::college_id), via Section -> Major -> Department ->
 *     College. A Dean/OIC with no College assigned sees nothing
 *     rather than silently falling back to "everything".
 *   - Assistant Dean            -> General Education subjects only
 *     (Subject::category === 'General Education'), which are shared
 *     across every Major/College rather than tied to one. This is
 *     scoped at the SectionSubject level, not the Section level,
 *     since GenEd subjects live inside Sections that otherwise
 *     belong to Majors the Assistant Dean has no visibility into.
 *     NOTE: the source spec also mentions "Minor subjects" — the
 *     Subject model currently only distinguishes 'Major' vs
 *     'General Education' (see subjects table), so that half of the
 *     scope isn't representable yet. Revisit if/when a Minor
 *     category is added.
 *   - Any other/no role         -> nothing (fail safe, not fail
 *     open).
 *
 * ACTIVE SEMESTER SCOPE
 * ------------------------------------------------------------------
 * Every widget is additionally scoped to the currently Active
 * Academic Term, via ScheduleConflictService::activeSemesterSectionIds()
 * — the same method the scheduling workspace's conflict checks use,
 * so the Dashboard's numbers can never quietly disagree with what
 * the Registrar sees while actually building the schedule.
 */
class DashboardService
{
    public function __construct(
        private readonly ScheduleConflictService $conflictService,
    ) {
    }

    /**
     * Everything the Dashboard page needs, already scoped to the
     * given user's role/College.
     *
     * @return array{
     *     scope: array{type: string, college_id: ?int, label: ?string},
     *     kpis: array{active_sections: int, faculty_members: int, rooms: int, scheduled_subjects: int, total_subjects: int},
     *     progress: array{overall_percent: int, sections_scheduled: int, sections_total: int, faculty_assigned: int, rooms_assigned: int, total_subjects: int},
     *     conflicts: array{faculty_conflicts: int, room_conflicts: int, time_conflicts: int, unscheduled_subjects: int, missing_faculty: int, missing_rooms: int},
     * }
     */
    public function overview(User $user): array
    {
        $scope = $this->scopeFor($user);
        $sectionIds = $this->scopedSectionIds($scope);

        return [
            'scope' => $scope,
            'kpis' => $this->kpis($scope, $sectionIds),
            'progress' => $this->schedulingProgress($scope, $sectionIds),
            'conflicts' => $this->conflictSummary($scope, $sectionIds),
        ];
    }

    /**
     * Resolves the widest scope the user's roles grant them. A user
     * can hold more than one role — Administrator/Registrar always
     * wins (institution-wide) over a narrower College/GenEd scope.
     *
     * @return array{type: string, college_id: ?int, label: ?string}
     */
    private function scopeFor(User $user): array
    {
        $roles = $user->getRoleNames();

        if ($roles->contains('Administrator') || $roles->contains('Registrar')) {
            return ['type' => 'institution', 'college_id' => null, 'label' => 'Institution-wide'];
        }

        if ($roles->contains('Dean') || $roles->contains('OIC')) {
            $user->loadMissing('college:id,name,short_name');

            return [
                'type' => 'college',
                'college_id' => $user->college_id,
                'label' => $user->college?->short_name ?? $user->college?->name,
            ];
        }

        if ($roles->contains('Assistant Dean')) {
            return ['type' => 'general_education', 'college_id' => null, 'label' => 'General Education Subjects'];
        }

        return ['type' => 'none', 'college_id' => null, 'label' => null];
    }

    /**
     * Every Section id the given scope is allowed to see, already
     * intersected with the active semester. 'general_education'
     * intentionally returns every active-semester Section id — GenEd
     * subjects can appear inside a Section belonging to any Major, so
     * the real filtering for that scope happens per-SectionSubject
     * (see sectionSubjectsQuery()), not per-Section.
     */
    private function scopedSectionIds(array $scope): Collection
    {
        $activeSectionIds = $this->conflictService->activeSemesterSectionIds();

        return match ($scope['type']) {
            'institution', 'general_education' => $activeSectionIds,
            'college' => $scope['college_id']
                ? Section::query()
                    ->whereIn('id', $activeSectionIds)
                    ->whereHas('major.department', fn (Builder $q) => $q->where('college_id', $scope['college_id']))
                    ->pluck('id')
                : collect(),
            default => collect(),
        };
    }

    /**
     * The scoped SectionSubject placements every widget below reads
     * from — every widget building its own query off this one method
     * is what keeps the KPI cards, Progress widget, and Conflict
     * panel from being able to quietly disagree with each other.
     */
    private function sectionSubjectsQuery(array $scope, Collection $sectionIds): Builder
    {
        $query = SectionSubject::query()->whereIn('section_id', $sectionIds);

        if ($scope['type'] === 'general_education') {
            $query->whereHas('subject', fn (Builder $q) => $q->where('category', 'General Education'));
        }

        return $query;
    }

    /**
     * @return array{active_sections: int, faculty_members: int, rooms: int, scheduled_subjects: int, total_subjects: int}
     */
    private function kpis(array $scope, Collection $sectionIds): array
    {
        $activeSections = Section::query()
            ->whereIn('id', $sectionIds)
            ->where('status', 'Active')
            ->count();

        $facultyQuery = Faculty::query()->where('status', 'Active');
        $roomQuery = Room::query()->where('status', 'Active');

        if ($scope['type'] === 'college') {
            $facultyQuery->where('college_id', $scope['college_id']);
            $roomQuery->where('college_id', $scope['college_id']);
        } elseif ($scope['type'] === 'general_education') {
            // GenEd Faculty/Rooms carry no College — see
            // Faculty::getFacultyCategoryAttribute().
            $facultyQuery->whereNull('college_id');
            $roomQuery->whereNull('college_id');
        } elseif ($scope['type'] === 'none') {
            $facultyQuery->whereRaw('1 = 0');
            $roomQuery->whereRaw('1 = 0');
        }

        $subjectsQuery = $this->sectionSubjectsQuery($scope, $sectionIds);

        return [
            'active_sections' => $activeSections,
            'faculty_members' => $facultyQuery->count(),
            'rooms' => $roomQuery->count(),
            'scheduled_subjects' => (clone $subjectsQuery)->where('status', 'Scheduled')->count(),
            'total_subjects' => (clone $subjectsQuery)->count(),
        ];
    }

    /**
     * @return array{overall_percent: int, sections_scheduled: int, sections_total: int, faculty_assigned: int, rooms_assigned: int, total_subjects: int}
     */
    private function schedulingProgress(array $scope, Collection $sectionIds): array
    {
        $subjectsQuery = $this->sectionSubjectsQuery($scope, $sectionIds);

        $totalSubjects = (clone $subjectsQuery)->count();
        $scheduledSubjects = (clone $subjectsQuery)->where('status', 'Scheduled')->count();
        $facultyAssigned = (clone $subjectsQuery)->whereNotNull('faculty_id')->count();
        $roomsAssigned = (clone $subjectsQuery)->whereNotNull('room_id')->count();

        // A Section counts as "Scheduled" once every one of its
        // (scope-filtered) subject placements is Scheduled — the same
        // "N/N assigned" idea SectionController::index() shows per
        // row, rolled up here into one scope-wide count.
        $perSection = (clone $subjectsQuery)
            ->selectRaw('section_id, COUNT(*) as total, SUM(status = "Scheduled") as scheduled')
            ->groupBy('section_id')
            ->get();

        return [
            'overall_percent' => $totalSubjects > 0 ? (int) round($scheduledSubjects / $totalSubjects * 100) : 0,
            'sections_scheduled' => $perSection->filter(
                fn ($row) => (int) $row->total > 0 && (int) $row->scheduled === (int) $row->total
            )->count(),
            'sections_total' => $perSection->count(),
            'faculty_assigned' => $facultyAssigned,
            'rooms_assigned' => $roomsAssigned,
            'total_subjects' => $totalSubjects,
        ];
    }

    /**
     * @return array{faculty_conflicts: int, room_conflicts: int, time_conflicts: int, unscheduled_subjects: int, missing_faculty: int, missing_rooms: int}
     */
    private function conflictSummary(array $scope, Collection $sectionIds): array
    {
        $subjectsQuery = $this->sectionSubjectsQuery($scope, $sectionIds);

        $missingFaculty = (clone $subjectsQuery)->whereNull('faculty_id')->count();
        $missingRooms = (clone $subjectsQuery)->whereNull('room_id')->count();
        $unscheduled = (clone $subjectsQuery)
            ->where(function (Builder $q) {
                $q->whereNull('days')->orWhereNull('start_time')->orWhereNull('end_time');
            })
            ->count();

        // Only fully-timed placements can meaningfully overlap —
        // pulled once and reused for all three overlap scans below.
        $placements = (clone $subjectsQuery)
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->get(['id', 'section_id', 'faculty_id', 'room_id', 'days', 'start_time', 'end_time']);

        return [
            // Faculty double-booked across any Section/College.
            'faculty_conflicts' => $this->countOverlapping($placements, 'faculty_id'),
            // Room double-booked across any Section/College.
            'room_conflicts' => $this->countOverlapping($placements, 'room_id'),
            // A single Section's own two classes overlapping each
            // other ("Time Conflicts" in the spec) — distinct from
            // the two checks above, which compare across Sections.
            'time_conflicts' => $this->countOverlapping($placements, 'section_id'),
            'unscheduled_subjects' => $unscheduled,
            'missing_faculty' => $missingFaculty,
            'missing_rooms' => $missingRooms,
        ];
    }

    /**
     * Counts placements that overlap another placement sharing the
     * same $groupKey (faculty_id / room_id / section_id), using the
     * exact same Day/Time overlap rule as
     * ScheduleConflictService::sharesDay()/overlaps() — so this
     * count can never quietly disagree with what the scheduling
     * workspace itself would flag as a conflict. Groups are small
     * (one faculty/room/section's own placements), so the pairwise
     * scan below stays cheap despite being O(n^2) per group.
     *
     * @param  Collection<int, SectionSubject>  $placements
     */
    private function countOverlapping(Collection $placements, string $groupKey): int
    {
        $conflictingIds = [];

        $placements
            ->filter(fn (SectionSubject $p) => $p->{$groupKey} !== null)
            ->groupBy($groupKey)
            ->each(function (Collection $group) use (&$conflictingIds) {
                $items = $group->values();

                for ($i = 0; $i < $items->count(); $i++) {
                    for ($j = $i + 1; $j < $items->count(); $j++) {
                        $a = $items[$i];
                        $b = $items[$j];

                        $daysA = array_values(array_filter(explode(',', (string) $a->days)));
                        $daysB = array_values(array_filter(explode(',', (string) $b->days)));

                        if (
                            $this->conflictService->sharesDay($daysA, $daysB)
                            && $this->conflictService->overlaps($a->start_time, $a->end_time, $b->start_time, $b->end_time)
                        ) {
                            $conflictingIds[$a->id] = true;
                            $conflictingIds[$b->id] = true;
                        }
                    }
                }
            });

        return count($conflictingIds);
    }
}