<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSectionSubjectRequest;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SectionSubjectController extends Controller
{
    /**
     * Section.year_level uses "First Year"… while CurriculumItem.year_level
     * uses "1st Year"… — this maps between the two so a Section's own
     * Year Level can be pre-selected when loading from a Curriculum.
     *
     * @var array<string, string>
     */
    private const YEAR_LEVEL_MAP = [
        'First Year' => '1st Year',
        'Second Year' => '2nd Year',
        'Third Year' => '3rd Year',
        'Fourth Year' => '4th Year',
    ];

    /**
     * Matches curriculum_items.semester's enum exactly. Sent to the
     * frontend so the "Load From Curriculum" Semester dropdown doesn't
     * hardcode a copy of this list.
     *
     * @var list<string>
     */
    private const SEMESTER_OPTIONS = ['First Semester', 'Second Semester', 'Summer'];

    /**
     * Display the "Section Subjects" landing page — a searchable list of
     * Sections, each linking into its own subject-assignment page.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('section_search', ''));

        $sections = Section::query()
            ->with(['major:id,name,code', 'curriculum:id,code,name,major_id'])
            ->withCount('subjects')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('section_code', 'like', "%{$search}%")
                        ->orWhere('section_name', 'like', "%{$search}%")
                        ->orWhere('academic_year', 'like', "%{$search}%")
                        ->orWhereHas('major', function ($majorQuery) use ($search) {
                            $majorQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('academic_year')
            ->orderBy('section_code')
            ->paginate(10, ['*'], 'section_page')
            ->withQueryString();

        return Inertia::render('Scheduling/SectionSubjects/Index', [
            'sections' => $sections,
            'filters' => ['section_search' => $search],
        ]);
    }

    /**
     * Display the subject-assignment page for a single Section.
     *
     * This is NOT the schedule — no faculty, room, or time is assigned
     * here. It only builds the list of Subjects the Section needs
     * scheduled, which the scheduling engine reads later.
     */
    public function show(Request $request, Section $section): Response
    {
        $section->load(['major:id,name,code', 'curriculum:id,code,name,major_id']);

        $search = trim((string) $request->query('subject_search', ''));

        $sectionSubjects = $section->sectionSubjects()
            ->with('subject')
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('subject', function ($subjectQuery) use ($search) {
                    $subjectQuery->where('subject_code', 'like', "%{$search}%")
                        ->orWhere('subject_title', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->get()
            ->sortBy(fn ($item) => $item->subject?->subject_code)
            ->values();

        // Curriculums available for "Load From Curriculum" — restricted to
        // the Section's own Major, since a Section's subjects should come
        // from a Curriculum that actually applies to it.
        $curriculums = Curriculum::query()
            ->where('major_id', $section->major_id)
            ->where('status', 'Active')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        // Active Subjects for "Manual Selection" — restricted to the
        // Section's own Major, plus true General Education subjects
        // (category = 'General Education', shared by every Major).
        // NOTE: Major-category subjects with a null major_id (e.g. the
        // BSCRIM shared core — FORENSIC1, ENHANCE2, CDI1, etc.) are
        // deliberately excluded here, not treated as universal — they
        // only apply to a *subset* of Majors (the 4 BSCRIM
        // specializations), which this schema's single major_id column
        // can't express. Including them here would leak them into
        // every other Major's picker (e.g. BSIT), which is the bug
        // being fixed. They're still reachable via "Load From
        // Curriculum" for BSCRIM sections, since CurriculumItem
        // references them directly regardless of major_id.
        $placedSubjectIds = $sectionSubjects->pluck('subject_id');

        $availableSubjects = Subject::query()
            ->where('is_active', true)
            ->where(function ($query) use ($section) {
                $query->where('major_id', $section->major_id)
                    ->orWhere('category', 'General Education');
            })
            ->whereNotIn('id', $placedSubjectIds)
            ->orderBy('subject_code')
            ->get(['id', 'subject_code', 'subject_title', 'category', 'units']);

        return Inertia::render('Scheduling/SectionSubjects/Show', [
            'section' => $section,
            'sectionSubjects' => $sectionSubjects,
            'filters' => ['subject_search' => $search],
            'curriculums' => $curriculums,
            'availableSubjects' => $availableSubjects,
            'yearLevelMap' => self::YEAR_LEVEL_MAP,
            'sectionYearLevel' => self::YEAR_LEVEL_MAP[$section->year_level] ?? null,
            'semesterOptions' => self::SEMESTER_OPTIONS,
        ]);
    }

    /**
     * Preview the Subjects a Curriculum + Year Level would load into the
     * Section, before anything is saved. Excludes Subjects already
     * placed in the Section, and is scoped to the Section's own Major.
     */
    public function curriculumPreview(Request $request, Section $section): JsonResponse
    {
        $validated = $request->validate([
            'curriculum_id' => ['required', 'integer', 'exists:curriculums,id'],
            'year_level' => ['required', 'string', Rule::in(array_values(self::YEAR_LEVEL_MAP))],
            'semester' => ['required', 'string', Rule::in(['First Semester', 'Second Semester', 'Summer'])],
        ]);

        $curriculum = Curriculum::where('id', $validated['curriculum_id'])
            ->where('major_id', $section->major_id)
            ->firstOrFail();

        $placedSubjectIds = $section->sectionSubjects()->pluck('subject_id');

        $subjects = CurriculumItem::query()
            ->where('curriculum_id', $curriculum->id)
            ->where('year_level', $validated['year_level'])
            ->where('semester', $validated['semester'])
            ->whereNotIn('subject_id', $placedSubjectIds)
            ->with('subject:id,subject_code,subject_title,category,units,lecture_hours,laboratory_hours')
            ->get()
            ->pluck('subject')
            ->filter()
            ->values();

        return response()->json(['subjects' => $subjects]);
    }

    /**
     * Add one or more Subjects to the Section — used by both "Load From
     * Curriculum" (after the user trims the preview) and "Manual
     * Selection". Duplicate subjects within the Section are rejected by
     * StoreSectionSubjectRequest.
     */
    public function store(StoreSectionSubjectRequest $request, Section $section): RedirectResponse
    {
        $validated = $request->validated();

        foreach ($validated['subject_ids'] as $subjectId) {
            SectionSubject::create([
                'section_id' => $section->id,
                'subject_id' => $subjectId,
                'source' => $validated['source'],
            ]);
        }

        $count = count($validated['subject_ids']);

        return redirect()
            ->route('scheduling.section-subjects.show', $section)
            ->with('success', $count === 1 ? 'Subject added to the section.' : "{$count} subjects added to the section.");
    }

    /**
     * Remove a Subject from the Section. Only the placement
     * (SectionSubject) is deleted — the master Subject record, and any
     * other Section it belongs to, is untouched.
     */
    public function destroy(Section $section, Subject $subject): RedirectResponse
    {
        $section->sectionSubjects()->where('subject_id', $subject->id)->delete();

        return redirect()
            ->route('scheduling.section-subjects.show', $section)
            ->with('success', 'Subject removed from the section.');
    }
}