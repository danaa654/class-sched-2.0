<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreviewSectionBatchRequest;
use App\Http\Requests\StoreSectionBatchRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\Curriculum;
use App\Models\Major;
use App\Models\Section;
use App\Services\SectionBatchGeneratorService;
use App\Support\AccessScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        $this->authorize('viewAny', Section::class);

        $search = trim((string) $request->query('section_search', ''));

        $sections = Section::query()
            ->visibleTo($request->user())
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
                // Practicum/OJT rows never have Faculty/Room/Days/Time
                // to fill in (see Subject::isPracticum()) — a row for
                // one of those subjects counts as "assigned" simply by
                // existing, same as SectionSubjectController's status
                // logic treats it as immediately 'Scheduled'.
                'sectionSubjects as assigned_subjects_count' => function ($query) {
                    $query->where(function ($inner) {
                        $inner->whereNotNull('faculty_id')
                            ->whereNotNull('room_id')
                            ->whereNotNull('days')
                            ->whereNotNull('start_time')
                            ->whereNotNull('end_time');
                    })->orWhereHas('subject', function ($subjectQuery) {
                        $subjectQuery->where('subject_type', 'practicum');
                    });
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
        //
        // SECURITY: scoped to the authenticated user's authorized
        // College(s). Never send every College's Majors to a
        // restricted (Dean/OIC) user just to hide them client-side —
        // that still lets the option be selected via devtools/a
        // scripted request. A Dean/OIC only ever sees their own
        // College's Majors here; Admin/Registrar/Assistant Dean see
        // all of them.
        $activeMajors = Major::query()
            ->where('status', 'Active')
            ->whereHas('department', function ($departmentQuery) use ($request) {
                $visibleCollegeIds = AccessScope::visibleCollegeIds($request->user());

                if ($visibleCollegeIds !== null) {
                    $departmentQuery->whereIn('college_id', $visibleCollegeIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'department_id']);

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
            'sectionTypes' => StoreSectionRequest::SECTION_TYPES,
            'academicYears' => $this->academicYearOptions(),
        ]);
    }

    /**
     * Store a newly created section.
     */
    public function store(StoreSectionRequest $request): RedirectResponse
    {
        // NEVER trust an implicit College from the frontend — derive it
        // from the Major record server-side (spec Section 23).
        $this->authorizeSectionCollege($request, (int) $request->validated('major_id'));

        // The StoreSectionRequest's "unique" rule already checks
        // section_code, but that check and this insert aren't atomic —
        // a double-submit (double-click, or a slow request the user
        // retries) can fire two requests that both pass validation
        // before either one has inserted. Catch that race here instead
        // of letting it surface as a raw 500 error page.
        try {
            Section::create($request->validated());
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'section_code' => 'This section code was just taken by another request. Please use a different code.',
            ]);
        }

        return redirect()->route('scheduling.sections')->with('success', 'Section added successfully.');
    }

    /**
     * Live preview for the Add Section modal: given a Section Prefix
     * and Number of Blocks, return the next available block names
     * (e.g. BSIT-1A, BSIT-1B), skipping any letters already used by
     * existing sections in the same Academic Year / Semester / Program.
     *
     * Read-only — nothing is created here.
     */
    public function previewBatch(PreviewSectionBatchRequest $request, SectionBatchGeneratorService $generator): JsonResponse
    {
        $data = $request->validated();

        // SECURITY: this endpoint is read-only (no rows are created),
        // but it still must not let a restricted user probe/preview
        // block names for a program outside their College — same
        // scope check as storeBatch() below, server-side only (spec
        // Section 23).
        $this->authorizeSectionCollege($request, (int) $data['major_id']);

        // Irregular sections are a single scheduling group, not a set
        // of A/B/C blocks — see nextIrregularName()'s docblock. This
        // keeps that branch entirely separate from the Regular
        // block-generation path below rather than forcing
        // number_of_blocks=1 through the same letter-suffixing logic.
        if ($data['section_type'] === 'Irregular') {
            $names = $generator->nextIrregularName($data['section_prefix']);

            $sections = collect($names)->map(fn (string $name) => [
                'section_code' => $name,
                'estimated_students' => $data['estimated_students'],
            ])->values();

            return response()->json(['sections' => $sections]);
        }

        $names = $generator->nextBlockNames(
            prefix: $data['section_prefix'],
            numberOfBlocks: $data['number_of_blocks'],
            academicYear: $data['academic_year'],
            semester: $data['semester'],
            yearLevel: $data['year_level'],
            majorId: (int) $data['major_id'],
            excludeSectionId: $data['exclude_section_id'] ?? null,
        );

        $sections = collect($names)->map(fn (string $name) => [
            'section_code' => $name,
            'estimated_students' => $data['estimated_students_per_block'],
        ])->values();

        return response()->json(['sections' => $sections]);
    }

    /**
     * Store a batch of sections generated from the Add Section flow
     * (Section Prefix + Number of Blocks → BSIT-1A, BSIT-1B, ...).
     *
     * Each row in the editable preview becomes its own real Section
     * record — no "parent section" grouping is introduced — sharing
     * the common Academic Year / Semester / Program / Year Level /
     * Prospectus (Curriculum) / Status / Remarks.
     */
    public function storeBatch(StoreSectionBatchRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // SECURITY: StoreSectionBatchRequest::authorize() intentionally
        // just returns true (validation-only, per Laravel FormRequest
        // convention used across this codebase) — the real check
        // belongs here, mirroring store()'s createForCollege check.
        // Without this, a restricted user could bypass the single
        // Add Section form entirely by hitting the batch endpoint
        // directly with a program outside their College (spec
        // Section 23).
        $this->authorizeSectionCollege($request, (int) $data['major_id']);

        try {
            DB::transaction(function () use ($data) {
                foreach ($data['sections'] as $row) {
                    Section::create([
                        'section_code' => $row['section_code'],
                        'section_name' => $row['section_code'],
                        'section_type' => $data['section_type'],
                        'major_id' => $data['major_id'],
                        'curriculum_id' => $data['curriculum_id'] ?? null,
                        'year_level' => $data['year_level'],
                        'academic_year' => $data['academic_year'],
                        'semester' => $data['semester'],
                        'estimated_students' => $row['estimated_students'],
                        'status' => $data['status'],
                        'remarks' => $data['remarks'] ?? null,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'sections' => 'One of these section names was just taken by another request. Please refresh and try again.',
            ]);
        }

        $count = count($data['sections']);

        return redirect()->route('scheduling.sections')->with(
            'success',
            $count === 1 ? '1 section was created successfully.' : "{$count} sections were created successfully."
        );
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
        $this->authorize('update', $section);

        try {
            $section->update($request->validated());
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'section_code' => 'This section code was just taken by another request. Please use a different code.',
            ]);
        }

        return back()->with('success', 'Section updated successfully.');
    }

    /**
     * Delete a section.
     */
    public function destroy(Section $section): RedirectResponse
    {
        $this->authorize('delete', $section);

        $section->delete();

        return redirect()->route('scheduling.sections')->with('success', 'Section deleted successfully.');
    }

    /**
     * Central "can this user create/preview a section for this Major's
     * College?" check, shared by store(), previewBatch(), and
     * storeBatch() so all three entry points into Section creation
     * enforce the exact same rule (spec Section 23).
     *
     * Resolves the owning College strictly from the Major record in
     * the database — a college_id/major_id supplied by the frontend
     * is never trusted on its own. Uses SectionPolicy::createForCollege,
     * which already encodes: Admin/Registrar bypass; Dean/OIC must
     * match their own college_id; Assistant Dean is never allowed
     * full Section CRUD (spec §8).
     *
     * @throws AuthorizationException
     */
    private function authorizeSectionCollege(Request $request, ?int $majorId): void
    {
        $ownerCollegeId = $majorId
            ? Major::with('department')->find($majorId)?->department?->college_id
            : null;

        $this->authorize('createForCollege', [Section::class, $ownerCollegeId]);
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