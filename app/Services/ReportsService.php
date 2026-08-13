<?php

namespace App\Services;

use App\Models\College;
use App\Models\Curriculum;
use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\SectionSubject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REPORTS — read-only summarization layer for the Reports page.
 *
 * This service never writes to the database and never re-implements
 * scheduling/conflict logic that already exists elsewhere. It only
 * queries the existing Section / SectionSubject / Faculty / Room /
 * Curriculum tables (and, for conflicts, calls back into
 * ScheduleConflictService — the single source of truth for what a
 * "conflict" is) and shapes the results into generic
 * {columns, rows} tables the Reports page can render with one
 * component, regardless of report type.
 */
class ReportsService
{
    /** Every report type this service knows how to generate, grouped for the sidebar/select. */
    public const REPORT_GROUPS = [
        'Scheduling' => [
            'master_schedule' => 'Master Schedule',
            'schedule_by_section' => 'Schedule by Section',
            'schedule_by_faculty' => 'Schedule by Faculty',
            'schedule_by_room' => 'Schedule by Room',
            'unscheduled_subjects' => 'Unscheduled Subjects',
            'scheduling_conflicts' => 'Scheduling Conflicts',
        ],
        'Faculty' => [
            'faculty_teaching_load' => 'Faculty Teaching Load',
            'faculty_availability' => 'Faculty Availability',
        ],
        'Rooms' => [
            'room_utilization' => 'Room Utilization',
            'room_conflicts' => 'Room Conflicts',
        ],
        'Academic' => [
            'sections_overview' => 'Sections Overview',
            'program_year_summary' => 'Program / Year Level Summary',
            'curriculum_report' => 'Curriculum / Prospectus Report',
            'section_subjects' => 'Section Subjects',
        ],
        'Irregular' => [
            'irregular_sections' => 'Irregular Sections',
            'irregular_merge_report' => 'Irregular Merge Report',
        ],
    ];

    public function __construct(
        private readonly ScheduleConflictService $conflicts,
    ) {}

    /**
     * Options every global filter dropdown needs, independent of any
     * selected report.
     */
    public function filterOptions(): array
    {
        return [
            'academicYears' => Section::query()
                ->whereNotNull('academic_year')
                ->distinct()
                ->orderByDesc('academic_year')
                ->pluck('academic_year'),
            'semesters' => ['First Semester', 'Second Semester', 'Summer'],
            'colleges' => College::query()
                ->orderBy('name')
                ->with(['departments.majors' => fn ($q) => $q->orderBy('name')])
                ->get()
                ->map(fn (College $college) => [
                    'id' => $college->id,
                    'name' => $college->short_name ?: $college->name,
                    'majors' => $college->departments->flatMap->majors->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                    ])->values(),
                ]),
            'sections' => Section::query()->orderBy('section_code')->get(['id', 'section_code', 'academic_year', 'semester']),
            'yearLevels' => Section::query()->whereNotNull('year_level')->distinct()->orderBy('year_level')->pluck('year_level'),
            'faculty' => Faculty::query()->orderBy('last_name')->get()->map(fn (Faculty $f) => ['id' => $f->id, 'name' => $f->full_name]),
            'rooms' => Room::query()->orderBy('room_code')->get(['id', 'room_code']),
            'reportGroups' => self::REPORT_GROUPS,
        ];
    }

    /**
     * The dashboard summary shown before a report is generated,
     * scoped to the selected Academic Year + Semester (defaults to
     * every Section when neither is selected).
     */
    public function dashboardSummary(?string $academicYear, ?string $semester): array
    {
        $sections = Section::query()
            ->when($academicYear, fn ($q, $v) => $q->where('academic_year', $v))
            ->when($semester, fn ($q, $v) => $q->where('semester', $v))
            ->get(['id', 'section_type', 'major_id']);

        $sectionIds = $sections->pluck('id');

        $sectionSubjects = SectionSubject::query()->whereIn('section_id', $sectionIds)->get();
        $scheduled = $sectionSubjects->where('status', 'Scheduled')->count();
        $total = $sectionSubjects->count();

        return [
            'total_programs' => \App\Models\Major::query()->count(),
            'total_sections' => $sections->count(),
            'regular_sections' => $sections->where('section_type', 'Regular')->count(),
            'irregular_sections' => $sections->where('section_type', 'Irregular')->count(),
            'total_subjects' => $total,
            'scheduled_subjects' => $scheduled,
            'unscheduled_subjects' => $total - $scheduled,
            'total_faculty' => Faculty::query()->count(),
            'total_rooms' => Room::query()->count(),
            'completion_percent' => $total > 0 ? round(($scheduled / $total) * 100) : 0,
        ];
    }

    /**
     * Dispatches to the requested report and returns a generic
     * {title, columns, rows} shape, plus any chart-ready series.
     */
    public function generate(string $reportType, array $filters): array
    {
        return match ($reportType) {
            'master_schedule' => $this->masterSchedule($filters),
            'schedule_by_section' => $this->scheduleBySection($filters),
            'schedule_by_faculty' => $this->scheduleByFaculty($filters),
            'schedule_by_room' => $this->scheduleByRoom($filters),
            'unscheduled_subjects' => $this->unscheduledSubjects($filters),
            'scheduling_conflicts' => $this->schedulingConflicts($filters),
            'faculty_teaching_load' => $this->facultyTeachingLoad($filters),
            'faculty_availability' => $this->facultyAvailability($filters),
            'room_utilization' => $this->roomUtilization($filters),
            'room_conflicts' => $this->schedulingConflicts($filters, onlyType: 'Room'),
            'sections_overview' => $this->sectionsOverview($filters),
            'program_year_summary' => $this->programYearSummary($filters),
            'curriculum_report' => $this->curriculumReport($filters),
            'section_subjects' => $this->sectionSubjectsReport($filters),
            'irregular_sections' => $this->irregularSections($filters),
            'irregular_merge_report' => $this->irregularMergeReport($filters),
            default => ['title' => 'Unknown Report', 'columns' => [], 'rows' => [], 'note' => 'This report type does not exist.'],
        };
    }

    // ------------------------------------------------------------
    // Shared query builders
    // ------------------------------------------------------------

    /**
     * Base Section query with every global filter applied.
     */
    private function sectionsQuery(array $filters)
    {
        return Section::query()
            ->with(['major.department.college'])
            ->when($filters['academic_year'] ?? null, fn ($q, $v) => $q->where('academic_year', $v))
            ->when($filters['semester'] ?? null, fn ($q, $v) => $q->where('semester', $v))
            ->when($filters['major_id'] ?? null, fn ($q, $v) => $q->where('major_id', $v))
            ->when($filters['year_level'] ?? null, fn ($q, $v) => $q->where('year_level', $v))
            ->when($filters['section_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when($filters['section_type'] ?? null, fn ($q, $v) => $q->where('section_type', $v))
            ->when($filters['college_id'] ?? null, function ($q, $collegeId) {
                $q->whereHas('major.department', fn ($dq) => $dq->where('college_id', $collegeId));
            });
    }

    /**
     * Base SectionSubject query, scoped through sectionsQuery() so
     * every report shares one definition of "which Sections are in
     * scope right now".
     */
    private function sectionSubjectsQuery(array $filters)
    {
        $sectionIds = $this->sectionsQuery($filters)->pluck('id');

        return SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->with(['section.major', 'subject', 'faculty', 'room']);
    }

    private function programName(SectionSubject|Section $row): string
    {
        $section = $row instanceof Section ? $row : $row->section;

        return $section?->major?->short_name ?? $section?->major?->name ?? '—';
    }

    // ------------------------------------------------------------
    // A. Scheduling Reports
    // ------------------------------------------------------------

    private function masterSchedule(array $filters): array
    {
        $rows = $this->sectionSubjectsQuery($filters)->get()->map(function (SectionSubject $ss) {
            $mergeInfo = '—';
            if ($ss->section?->isIrregular()) {
                $mergeInfo = $ss->is_merged
                    ? 'Merged with '.($ss->mergedInto?->section?->section_code ?? '—')
                    : 'Independent';
            }

            return [
                'Section' => $ss->section?->section_code,
                'Section Type' => $ss->section?->section_type,
                'Program' => $this->programName($ss),
                'Year Level' => $ss->section?->year_level,
                'Subject Code' => $ss->subject?->subject_code,
                'Subject' => $ss->subject?->subject_title,
                'Units' => $ss->subject?->units,
                'Faculty' => $ss->faculty?->full_name,
                'Room' => $ss->room?->room_code,
                'Day' => $ss->days,
                'Start Time' => $this->formatTime12h($ss->start_time),
                'End Time' => $this->formatTime12h($ss->end_time),
                'Schedule Status' => $ss->status,
                'Irregular Handling' => $mergeInfo,
            ];
        });

        return $this->table('Master Schedule', $rows);
    }

    private function scheduleBySection(array $filters): array
    {
        $rows = $this->sectionSubjectsQuery($filters)->get()->map(fn (SectionSubject $ss) => [
            'Section' => $ss->section?->section_code,
            'Subject Code' => $ss->subject?->subject_code,
            'Subject' => $ss->subject?->subject_title,
            'Faculty' => $ss->faculty?->full_name,
            'Room' => $ss->room?->room_code,
            'Day' => $ss->days,
            'Start' => $this->formatTime12h($ss->start_time),
            'End' => $this->formatTime12h($ss->end_time),
            'Type' => $ss->section?->section_type,
        ]);

        return $this->table('Schedule by Section', $rows);
    }

    private function scheduleByFaculty(array $filters): array
    {
        $query = $this->sectionSubjectsQuery($filters);

        if (! empty($filters['faculty_id'])) {
            $query->where('faculty_id', $filters['faculty_id']);
        } else {
            $query->whereNotNull('faculty_id');
        }

        $rows = $query->get()->map(fn (SectionSubject $ss) => [
            'Faculty' => $ss->faculty?->full_name,
            'Subject' => $ss->subject?->subject_title,
            'Section' => $ss->section?->section_code,
            'Room' => $ss->room?->room_code,
            'Day' => $ss->days,
            'Start' => $this->formatTime12h($ss->start_time),
            'End' => $this->formatTime12h($ss->end_time),
            'Units' => $ss->subject?->units,
        ]);

        return $this->table('Schedule by Faculty', $rows);
    }

    private function scheduleByRoom(array $filters): array
    {
        $query = $this->sectionSubjectsQuery($filters);

        if (! empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        } else {
            $query->whereNotNull('room_id');
        }

        $rows = $query->get()->map(fn (SectionSubject $ss) => [
            'Room' => $ss->room?->room_code,
            'Subject' => $ss->subject?->subject_title,
            'Section' => $ss->section?->section_code,
            'Faculty' => $ss->faculty?->full_name,
            'Day' => $ss->days,
            'Start' => $this->formatTime12h($ss->start_time),
            'End' => $this->formatTime12h($ss->end_time),
            'Room Capacity' => $ss->room?->capacity,
        ]);

        return $this->table('Schedule by Room', $rows);
    }

    private function unscheduledSubjects(array $filters): array
    {
        $rows = $this->sectionSubjectsQuery($filters)
            ->where('status', '!=', 'Scheduled')
            ->get()
            ->map(function (SectionSubject $ss) {
                $has = fn ($v) => filled($v);
                $partial = $has($ss->faculty_id) || $has($ss->room_id) || $has($ss->days);
                $reason = match (true) {
                    ! $has($ss->faculty_id) && ! $has($ss->room_id) && ! $has($ss->days) => 'No faculty, room, or time assigned yet.',
                    ! $has($ss->faculty_id) => 'Faculty not yet assigned.',
                    ! $has($ss->room_id) => 'Room not yet assigned.',
                    ! $has($ss->days) || ! $has($ss->start_time) => 'Day/Time not yet assigned.',
                    default => 'Incomplete schedule.',
                };

                return [
                    'Section' => $ss->section?->section_code,
                    'Section Type' => $ss->section?->section_type,
                    'Program' => $this->programName($ss),
                    'Subject Code' => $ss->subject?->subject_code,
                    'Subject' => $ss->subject?->subject_title,
                    'Faculty' => $ss->faculty?->full_name ?? '—',
                    'Room' => $ss->room?->room_code ?? '—',
                    'Scheduling Status' => $partial ? 'Partially Scheduled' : 'Unscheduled',
                    'Reason' => $reason,
                ];
            });

        return $this->table('Unscheduled Subjects', $rows, emptyMessage: 'All section subjects are currently scheduled.');
    }

    /**
     * Reuses ScheduleConflictService's own overlap-detection methods
     * (findFacultyConflict / findRoomConflict / findSectionConflict) —
     * it never re-derives what counts as a conflict.
     */
    private function schedulingConflicts(array $filters, ?string $onlyType = null): array
    {
        $placements = $this->sectionSubjectsQuery($filters)
            ->where(fn ($q) => $q->whereNotNull('faculty_id')->orWhereNotNull('room_id'))
            ->get()
            ->filter(fn (SectionSubject $ss) => $ss->days && $ss->start_time && $ss->end_time);

        $seenPairs = [];
        $rows = collect();

        foreach ($placements as $ss) {
            $dayTokens = array_filter(explode(',', (string) $ss->days));

            $checks = [
                'Faculty' => fn () => $ss->faculty_id
                    ? $this->conflicts->findFacultyConflict($ss->faculty_id, $ss->id, $dayTokens, $ss->start_time, $ss->end_time)
                    : null,
                'Room' => fn () => $ss->room_id
                    ? $this->conflicts->findRoomConflict($ss->room_id, $ss->id, $dayTokens, $ss->start_time, $ss->end_time)
                    : null,
                'Section' => fn () => $this->conflicts->findSectionConflict($ss->section_id, $ss->id, $dayTokens, $ss->start_time, $ss->end_time),
            ];

            foreach ($checks as $type => $check) {
                if ($onlyType && $type !== $onlyType) {
                    continue;
                }

                $conflictWith = $check();
                if (! $conflictWith) {
                    continue;
                }

                $pairKey = $type.':'.min($ss->id, $conflictWith->id).'-'.max($ss->id, $conflictWith->id);
                if (isset($seenPairs[$pairKey])) {
                    continue;
                }
                $seenPairs[$pairKey] = true;

                $conflictWith->loadMissing(['section', 'subject', 'faculty', 'room']);

                $rows->push([
                    'Conflict Type' => $type,
                    'Section A' => $ss->section?->section_code,
                    'Subject A' => $ss->subject?->subject_code,
                    'Section B' => $conflictWith->section?->section_code,
                    'Subject B' => $conflictWith->subject?->subject_code,
                    'Faculty' => $ss->faculty?->full_name ?? '—',
                    'Room' => $ss->room?->room_code ?? '—',
                    'Day' => $ss->days,
                    'Time' => $this->formatTimeRange12h($ss->start_time, $ss->end_time),
                ]);
            }
        }

        return $this->table('Scheduling Conflicts', $rows, emptyMessage: 'No scheduling conflicts detected.');
    }

    // ------------------------------------------------------------
    // B. Faculty Reports
    // ------------------------------------------------------------

    private function facultyTeachingLoad(array $filters): array
    {
        $facultyQuery = Faculty::query()->with('college');
        if (! empty($filters['college_id'])) {
            $facultyQuery->where('college_id', $filters['college_id']);
        }

        $sectionIds = $this->sectionsQuery($filters)->pluck('id');

        $rows = $facultyQuery->get()->map(function (Faculty $faculty) use ($sectionIds) {
            $placements = SectionSubject::query()
                ->where('faculty_id', $faculty->id)
                ->whereIn('section_id', $sectionIds)
                ->with('subject')
                ->get();

            $hours = $placements->sum(fn (SectionSubject $ss) => $ss->start_time && $ss->end_time
                ? (strtotime($ss->end_time) - strtotime($ss->start_time)) / 3600 * max(1, count(explode(',', (string) $ss->days)))
                : 0);

            return [
                'Faculty' => $faculty->full_name,
                'Program' => $faculty->college?->short_name ?? $faculty->college?->name ?? 'General Education',
                'Number of Subjects' => $placements->pluck('subject_id')->unique()->count(),
                'Number of Sections' => $placements->pluck('section_id')->unique()->count(),
                'Total Units' => $placements->sum(fn ($ss) => $ss->subject?->units ?? 0),
                'Total Scheduled Hours' => round($hours, 1),
            ];
        })->filter(fn ($row) => empty($filters['faculty_id']) || true)->values();

        $assigned = $rows->where('Number of Subjects', '>', 0)->count();

        return $this->table('Faculty Teaching Load', $rows, summary: [
            'Total Faculty' => $rows->count(),
            'Faculty Assigned' => $assigned,
            'Faculty Unassigned' => $rows->count() - $assigned,
            'Average Teaching Load' => $rows->count() ? round($rows->avg('Total Units'), 1) : 0,
        ]);
    }

    private function facultyAvailability(array $filters): array
    {
        $query = FacultyAvailability::query()->with('faculty');
        if (! empty($filters['faculty_id'])) {
            $query->where('faculty_id', $filters['faculty_id']);
        }

        $rows = $query->get()->map(fn (FacultyAvailability $a) => [
            'Faculty' => $a->faculty?->full_name,
            'Day' => $a->day_of_week,
            'Available From' => $this->formatTime12h($a->start_time),
            'Available Until' => $this->formatTime12h($a->end_time),
            'Availability Status' => $a->is_available ? 'Available' : 'Unavailable',
        ]);

        return $this->table('Faculty Availability', $rows);
    }

    // ------------------------------------------------------------
    // C. Room Reports
    // ------------------------------------------------------------

    /**
     * Utilization here is computed directly from the same
     * Section/SectionSubject records as every other report (scoped to
     * the selected Academic Year/Semester rather than only the Active
     * term, since a Report may look at a past or future term) — but
     * still classified with utilizationStatus()-equivalent thresholds
     * already established by RoomUtilizationService, not a new rule.
     */
    private function roomUtilization(array $filters): array
    {
        $sectionIds = $this->sectionsQuery($filters)->pluck('id');
        $schoolYear = SchoolYear::query()->where('name', $filters['academic_year'] ?? null)->first() ?? SchoolYear::active();
        $days = $schoolYear ? $schoolYear->allowedDays() : SchoolYear::DEFAULT_CLASS_DAYS;

        $roomQuery = Room::query();
        if (! empty($filters['room_id'])) {
            $roomQuery->where('id', $filters['room_id']);
        }

        $placementsByRoom = SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->whereNotNull('room_id')
            ->whereNotNull('start_time')
            ->get()
            ->groupBy('room_id');

        $rows = $roomQuery->get()->map(function (Room $room) use ($placementsByRoom, $days) {
            $placements = $placementsByRoom->get($room->id, collect());
            $hours = $placements->sum(fn (SectionSubject $ss) => (strtotime($ss->end_time) - strtotime($ss->start_time)) / 3600 * max(1, count(array_filter(explode(',', (string) $ss->days)))));
            $maxHours = 8 * count($days);
            $percent = $maxHours > 0 ? round(($hours / $maxHours) * 100, 1) : 0;

            return [
                'Room' => $room->room_code,
                'Room Type' => $room->room_type,
                'Capacity' => $room->capacity,
                'Number of Scheduled Classes' => $placements->count(),
                'Total Scheduled Hours' => round($hours, 1),
                'Utilization Percentage' => $percent,
            ];
        });

        return $this->table('Room Utilization', $rows, summary: [
            'Total Rooms' => $rows->count(),
            'Rooms in Use' => $rows->where('Number of Scheduled Classes', '>', 0)->count(),
            'Rooms Unused' => $rows->where('Number of Scheduled Classes', 0)->count(),
            'Most Utilized Room' => $rows->sortByDesc('Utilization Percentage')->first()['Room'] ?? '—',
        ]);
    }

    // ------------------------------------------------------------
    // D. Academic Reports
    // ------------------------------------------------------------

    private function sectionsOverview(array $filters): array
    {
        $rows = $this->sectionsQuery($filters)->withCount('sectionSubjects')->get()->map(function (Section $section) {
            $scheduled = $section->sectionSubjects()->where('status', 'Scheduled')->count();

            return [
                'Section' => $section->section_code,
                'Section Type' => $section->section_type,
                'Program' => $this->programName($section),
                'Year Level' => $section->year_level,
                'Academic Year' => $section->academic_year,
                'Semester' => $section->semester,
                'Estimated Students' => $section->estimated_students,
                'Status' => $section->status,
                'Number of Subjects' => $section->section_subjects_count,
                'Scheduling Status' => $section->section_subjects_count === 0
                    ? 'No Subjects'
                    : ($scheduled === $section->section_subjects_count ? 'Scheduled' : ($scheduled > 0 ? 'Partially Scheduled' : 'Unscheduled')),
            ];
        });

        return $this->table('Sections Overview', $rows);
    }

    private function programYearSummary(array $filters): array
    {
        $sections = $this->sectionsQuery($filters)->withCount('sectionSubjects')->get();

        $rows = $sections->groupBy(fn (Section $s) => $this->programName($s).'|'.$s->year_level)
            ->map(function (Collection $group) {
                $first = $group->first();
                $subjectIds = SectionSubject::query()->whereIn('section_id', $group->pluck('id'))->get();

                return [
                    'Program' => $this->programName($first),
                    'Year Level' => $first->year_level,
                    'Number of Sections' => $group->count(),
                    'Regular Sections' => $group->where('section_type', 'Regular')->count(),
                    'Irregular Sections' => $group->where('section_type', 'Irregular')->count(),
                    'Number of Subjects' => $subjectIds->count(),
                    'Scheduled Subjects' => $subjectIds->where('status', 'Scheduled')->count(),
                    'Unscheduled Subjects' => $subjectIds->where('status', '!=', 'Scheduled')->count(),
                ];
            })->values();

        return $this->table('Program / Year Level Summary', $rows);
    }

    private function curriculumReport(array $filters): array
    {
        $query = Curriculum::query()->with(['major', 'items.subject']);
        if (! empty($filters['major_id'])) {
            $query->where('major_id', $filters['major_id']);
        }

        $rows = collect();
        foreach ($query->get() as $curriculum) {
            foreach ($curriculum->items as $item) {
                $rows->push([
                    'Curriculum' => $curriculum->code ?? $curriculum->name,
                    'Program' => $curriculum->major?->short_name ?? $curriculum->major?->name,
                    'Curriculum Year' => trim(($curriculum->start_year ?? '').'-'.($curriculum->end_year ?? ''), '-'),
                    'Year Level' => $item->year_level,
                    'Semester' => $item->semester,
                    'Subject Code' => $item->subject?->subject_code,
                    'Subject' => $item->subject?->subject_title,
                    'Units' => $item->subject?->units,
                ]);
            }
        }

        return $this->table('Curriculum / Prospectus Report', $rows);
    }

    private function sectionSubjectsReport(array $filters): array
    {
        $query = $this->sectionSubjectsQuery($filters);
        if (! empty($filters['section_id'])) {
            $query->where('section_id', $filters['section_id']);
        }

        $rows = $query->get()->map(fn (SectionSubject $ss) => [
            'Section' => $ss->section?->section_code,
            'Subject Code' => $ss->subject?->subject_code,
            'Subject' => $ss->subject?->subject_title,
            'Units' => $ss->subject?->units,
            'Faculty' => $ss->faculty?->full_name ?? '—',
            'Room' => $ss->room?->room_code ?? '—',
            'Schedule' => $ss->days && $ss->start_time ? "{$ss->days} ".$this->formatTimeRange12h($ss->start_time, $ss->end_time) : '—',
            'Scheduling Status' => $ss->status,
            'Source' => $ss->source,
        ]);

        return $this->table('Section Subjects', $rows);
    }

    // ------------------------------------------------------------
    // E. Irregular Section Reports
    // ------------------------------------------------------------

    private function irregularSections(array $filters): array
    {
        $filters['section_type'] = 'Irregular';

        $rows = $this->sectionsQuery($filters)->withCount('sectionSubjects')->get()->map(function (Section $section) {
            $subjects = SectionSubject::query()->where('section_id', $section->id)->get();

            return [
                'Section' => $section->section_code,
                'Program' => $this->programName($section),
                'Year Level' => $section->year_level,
                'Academic Year' => $section->academic_year,
                'Semester' => $section->semester,
                'Estimated Students' => $section->estimated_students,
                'Number of Subjects' => $subjects->count(),
                'Scheduled Subjects' => $subjects->where('status', 'Scheduled')->count(),
                'Merged Subjects' => $subjects->where('is_merged', true)->count(),
                'Independent Subjects' => $subjects->where('status', 'Scheduled')->where('is_merged', false)->count(),
            ];
        });

        return $this->table('Irregular Sections', $rows);
    }

    /**
     * Reads directly off the existing Merge relationship
     * (SectionSubject::is_merged / mergedInto()) — no merge logic is
     * re-derived here, only displayed.
     */
    private function irregularMergeReport(array $filters): array
    {
        $filters['section_type'] = 'Irregular';
        $sectionIds = $this->sectionsQuery($filters)->pluck('id');

        $rows = SectionSubject::query()
            ->whereIn('section_id', $sectionIds)
            ->where('is_merged', true)
            ->with(['section', 'subject', 'mergedInto.section', 'mergedInto.faculty', 'mergedInto.room', 'mergedInto.mergedPlacements'])
            ->get()
            ->map(function (SectionSubject $ss) {
                $host = $ss->mergedInto;
                $existingStudents = $host?->section?->estimated_students ?? 0;
                $irregularStudents = $ss->section?->estimated_students ?? 0;
                $combined = $existingStudents + ($host?->mergedPlacements->sum(fn ($p) => $p->section?->estimated_students ?? 0) ?? 0);

                return [
                    'Irregular Section' => $ss->section?->section_code,
                    'Subject' => $ss->subject?->subject_title,
                    'Regular Section' => $host?->section?->section_code ?? '—',
                    'Faculty' => $host?->faculty?->full_name ?? '—',
                    'Room' => $host?->room?->room_code ?? '—',
                    'Day' => $host?->days ?? '—',
                    'Time' => $host ? $this->formatTimeRange12h($host->start_time, $host->end_time) : '—',
                    'Estimated Irregular Students' => $irregularStudents,
                    'Existing Class Students' => $existingStudents,
                    'Combined Students' => $combined,
                    'Room Capacity' => $host?->room?->capacity ?? '—',
                    'Merge Status' => 'Merged',
                ];
            });

        return $this->table('Irregular Merge Report', $rows, emptyMessage: 'No irregular subjects are currently merged.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * Formats a "HH:mm" (or "HH:mm:ss") time string as 12-hour
     * "h:mm AM/PM", matching the same convention already used on the
     * Rooms page's Weekly Timetable. Leaves non-time-like values
     * (null, already-formatted strings) untouched.
     */
    private function formatTime12h(?string $time): ?string
    {
        if (! $time) {
            return $time;
        }

        $parts = explode(':', $time);
        if (count($parts) < 2 || ! is_numeric($parts[0])) {
            return $time;
        }

        $hours = (int) $parts[0];
        $minutes = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $suffix = $hours >= 12 ? 'PM' : 'AM';
        $hours %= 12;
        if ($hours === 0) {
            $hours = 12;
        }

        return "{$hours}:{$minutes} {$suffix}";
    }

    /**
     * Formats a "HH:mm–HH:mm" (en dash separated) range using
     * formatTime12h() on each side.
     */
    private function formatTimeRange12h(?string $start, ?string $end): string
    {
        if (! $start || ! $end) {
            return '—';
        }

        return $this->formatTime12h($start).'–'.$this->formatTime12h($end);
    }

    private function table(string $title, Collection $rows, ?string $emptyMessage = null, ?array $summary = null): array
    {
        $rows = $rows->values();
        $columns = $rows->isNotEmpty() ? array_keys($rows->first()) : [];

        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'summary' => $summary,
            'empty_message' => $emptyMessage ?? 'No data found for the selected filters.',
        ];
    }
}