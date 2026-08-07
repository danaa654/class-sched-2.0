<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\Curriculum;
use App\Models\Major;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SectionController extends Controller
{
    /**
     * Display the Sections page.
     *
     * A Section represents a group of students, under a Major /
     * Curriculum / Year Level, that will later receive a class
     * schedule. This page only stores the section itself — subjects,
     * faculty, rooms, and schedules are assigned in later modules.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('section_search', ''));

        $sections = Section::query()
            ->with(['major:id,name,code', 'curriculum:id,code,name,major_id'])
            // Scheduling-progress indicator for the list — counts every
            // placement that has Faculty, Room, Days, Start, and End
            // Time all filled in, regardless of the row's `status`
            // column. A Section can show "12/12 assigned" here while
            // its rows still say Draft, because Auto Generate results
            // aren't finalized (status flips to Scheduled) until the
            // Registrar clicks Accept All & Save — this count answers
            // "has this section already been worked on?", not "is it
            // finalized?".
            ->withCount([
                'sectionSubjects as total_subjects_count',
                'sectionSubjects as assigned_subjects_count' => function ($query) {
                    $query->whereNotNull('faculty_id')
                        ->whereNotNull('room_id')
                        ->whereNotNull('days')
                        ->whereNotNull('start_time')
                        ->whereNotNull('end_time');
                },
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('section_code', 'like', "%{$search}%")
                        ->orWhere('section_name', 'like', "%{$search}%")
                        ->orWhere('academic_year', 'like', "%{$search}%")
                        ->orWhere('year_level', 'like', "%{$search}%")
                        ->orWhereHas('major', function ($majorQuery) use ($search) {
                            $majorQuery->where('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('curriculum', function ($curriculumQuery) use ($search) {
                            $curriculumQuery->where('code', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('academic_year')
            ->orderBy('section_code')
            ->paginate(10, ['*'], 'section_page')
            ->withQueryString();

        // Major dropdown for the Add/Edit dialog — Active majors only.
        $activeMajors = Major::query()
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        // Curriculum dropdown data — the frontend filters this list down
        // to the curriculums belonging to the selected Major.
        $curriculums = Curriculum::query()
            ->where('status', 'Active')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'major_id']);

        return Inertia::render('Scheduling/Sections/Index', [
            'sections' => $sections,
            'filters' => ['section_search' => $search],
            'activeMajors' => $activeMajors,
            'curriculums' => $curriculums,
            'yearLevels' => StoreSectionRequest::YEAR_LEVELS,
            'semesterOptions' => StoreSectionRequest::SEMESTERS,
            'academicYears' => $this->academicYearOptions(),
        ]);
    }

    /**
     * Store a newly created section.
     */
    public function store(StoreSectionRequest $request): RedirectResponse
    {
        Section::create($request->validated());

        return redirect()->route('scheduling.sections')->with('success', 'Section added successfully.');
    }

    /**
     * Update an existing section.
     *
     * Redirects back to wherever the request came from — the quick-edit
     * dialog on the Sections list, or the Section Information tab of
     * the Edit Section workspace — instead of always bouncing to the
     * Sections list.
     */
    public function update(UpdateSectionRequest $request, Section $section): RedirectResponse
    {
        $section->update($request->validated());

        return back()->with('success', 'Section updated successfully.');
    }

    /**
     * Delete a section.
     */
    public function destroy(Section $section): RedirectResponse
    {
        $section->delete();

        return redirect()->route('scheduling.sections')->with('success', 'Section deleted successfully.');
    }

    /**
     * Build a rolling list of Academic Year options (e.g. "2026-2027"),
     * spanning a couple of years back and several years ahead of today.
     *
     * @return list<string>
     */
    private function academicYearOptions(): array
    {
        $currentYear = (int) now()->format('Y');
        $startYear = $currentYear - 1;

        return collect(range($startYear, $startYear + 6))
            ->map(fn (int $year) => "{$year}-" . ($year + 1))
            ->values()
            ->all();
    }
}