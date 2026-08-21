<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreviewSectionBatchRequest;
use App\Http\Requests\StoreSectionBatchRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\AcademicTerm;
use App\Models\College;
use App\Models\Curriculum;
use App\Models\Major;
use App\Models\Section;
use App\Services\NotificationService;
use App\Services\SectionBatchGeneratorService;
use App\Support\AccessScope;
use App\Support\ViewingTerm;
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

        // Term filter — "which Academic Term's Sections am I looking
        // at?" Value is either "all" (no filtering, the historical
        // behavior) or "{academic_year}|{semester}" matching Section's
        // own academic_year/semester columns directly (already in the
        // "First Semester"/"Second Semester"/"Summer" spelling, so no
        // AcademicTerm::sectionSemesterValue() translation is needed
        // here — that translation only matters when starting from an
        // AcademicTerm record, which the default below does).
        //
        // No explicit ?term= given: default to THIS user's Viewing
        // Term (their session override if Admin/Registrar switched
        // it, else the real Active term) if one exists, otherwise
        // fall back to "all" (nothing to default to — better to show
        // everything than silently show nothing).
        $viewingTerm = ViewingTerm::resolve($request);
        $defaultTerm = 'all';
        if ($viewingTerm) {
            $viewingTerm->loadMissing('schoolYear:id,name');
            $viewingSemesterValue = $viewingTerm->sectionSemesterValue();
            if ($viewingTerm->schoolYear && $viewingSemesterValue) {
                $defaultTerm = "{$viewingTerm->schoolYear->name}|{$viewingSemesterValue}";
            }
        }

        $term = trim((string) $request->query('term', $defaultTerm));

        // College filter — "college_id" must be one of the Colleges the
        // current user is actually authorized to see (AccessScope::
        // visibleCollegeIds()), same server-side authority the Program
        // dropdown already uses below. A Dean/OIC passing a foreign
        // college_id simply matches nothing rather than leaking another
        // College's Sections — never trust the raw query value alone.
        $collegeId = $request->query('college_id');
        $collegeId = ($collegeId !== null && $collegeId !== '' && $collegeId !== 'all')
            ? (int) $collegeId
            : null;

        if ($collegeId !== null) {
            $visibleCollegeIds = AccessScope::visibleCollegeIds($request->user());
            if ($visibleCollegeIds !== null && ! in_array($collegeId, $visibleCollegeIds, true)) {
                $collegeId = -1; // outside this user's scope — match nothing
            }
        }

        // Year Level filter — validated against the same YEAR_LEVELS
        // enum the Add/Edit Section form already uses, so an invalid
        // value is simply ignored rather than silently returning zero
        // rows or leaking a raw SQL value into the query.
        $yearLevel = trim((string) $request->query('year_level', ''));
        if ($yearLevel !== '' && ! in_array($yearLevel, StoreSectionRequest::YEAR_LEVELS, true)) {
            $yearLevel = '';
        }

        // Scheduling Status filter — derived purely from the same
        // database facts SectionController::finalize() and the
        // scheduling-progress withCount() below already use as the
        // single source of truth (no second/duplicate definition):
        //   - is_finalized                → Finalized / Locked
        //   - a section_subjects row with status = 'Conflict'         → Needs Attention
        //   - no section_subjects rows at all                         → No Subjects Yet
        //   - every row "assigned" (Faculty/Room/Days/Start/End, or
        //     Practicum) and none conflicting                         → Fully Scheduled
        //   - anything else (has subjects, not fully assigned, no
        //     conflicts, not finalized)                                → In Progress
        $schedulingStatus = trim((string) $request->query('scheduling_status', 'all'));
        if (! in_array($schedulingStatus, ['all', 'no_subjects', 'in_progress', 'fully_scheduled', 'finalized', 'needs_attention'], true)) {
            $schedulingStatus = 'all';
        }

        $sections = Section::query()
            ->visibleTo($request->user())
            ->with(['major:id,name,code', 'curriculum:id,code,name,major_id'])
            ->when($term !== 'all', function ($query) use ($term) {
                [$termYear, $termSemester] = array_pad(explode('|', $term, 2), 2, null);

                if ($termYear && $termSemester) {
                    $query->where('academic_year', $termYear)
                        ->where('semester', $termSemester);
                }
            })
            ->when($collegeId !== null, function ($query) use ($collegeId) {
                $query->whereHas('major.department', function ($inner) use ($collegeId) {
                    $inner->where('college_id', $collegeId);
                });
            })
            ->when($yearLevel !== '', function ($query) use ($yearLevel) {
                $query->where('year_level', $yearLevel);
            })
            ->when($schedulingStatus !== 'all', function ($query) use ($schedulingStatus) {
                $this->applySchedulingStatusFilter($query, $schedulingStatus);
            })
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

        // College filter dropdown — same visibility scope as the
        // Program dropdown above (AccessScope::visibleCollegeIds()): a
        // Dean/OIC only ever sees their own College here, never every
        // College in the database.
        $visibleCollegeIds = AccessScope::visibleCollegeIds($request->user());
        $colleges = College::query()
            ->when($visibleCollegeIds !== null, fn ($query) => $query->whereIn('id', $visibleCollegeIds))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('Scheduling/Sections/Index', [
            'sections' => $sections,
            'filters' => [
                'section_search' => $search,
                'term' => $term,
                'college_id' => $collegeId,
                'year_level' => $yearLevel,
                'scheduling_status' => $schedulingStatus,
            ],
            'termOptions' => $this->termFilterOptions(),
            'activeMajors' => $activeMajors,
            'curriculums' => $curriculums,
            'colleges' => $colleges,
            'yearLevels' => StoreSectionRequest::YEAR_LEVELS,
            'sectionTypes' => StoreSectionRequest::SECTION_TYPES,
            'academicTermOptions' => $this->academicTermSectionOptions(),
        ]);
    }

    /**
     * Store a newly created section.
     */
    public function store(StoreSectionRequest $request, NotificationService $notifications): RedirectResponse
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
        //
        // TRANSACTIONAL CREATE + NOTIFY — same reasoning as finalize()/
        // unlock() below: the insert, its audit row, and the Dean/OIC
        // "Section Created" notification all commit together or not
        // at all.
        try {
            DB::transaction(function () use ($request, $notifications) {
                $section = Section::create($request->validated());
                $notifications->created($section, $request->user());
            });
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
    public function storeBatch(StoreSectionBatchRequest $request, NotificationService $notifications): RedirectResponse
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
            DB::transaction(function () use ($data, $request, $notifications) {
                foreach ($data['sections'] as $row) {
                    $section = Section::create([
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

                    // One "Section Created" notification per row — a
                    // batch of BSIT-1A/1B/1C is still each its own
                    // real Section (see this method's docblock), so
                    // each gets its own notification rather than one
                    // rolled-up "3 sections created" message.
                    $notifications->created($section, $request->user());
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
     * SECTION-LEVEL SCHEDULE FINALIZATION.
     *
     * Locks this Section's schedule so no further writes (Room Grid
     * drag, manual cell edit, Save Schedule, Auto Generate) can touch
     * it — see ScheduleConflictService::lockResources(), which is the
     * actual runtime-enforced gate this flag controls. This endpoint
     * only flips the flag; it does not itself validate that the
     * schedule is "complete" — a Dean/Registrar can finalize a
     * partially-scheduled Section deliberately (e.g. remaining rows
     * are intentionally TBA), same as they can Save Schedule with
     * gaps today.
     */
    public function finalize(Request $request, Section $section, NotificationService $notifications): RedirectResponse
    {
        $this->authorize('finalize', $section);

        // Only a FULLY scheduled Section may be finalized — every
        // SectionSubject must have Faculty/Room/Days/Start/End all
        // filled in (or be a Practicum/OJT row, which never needs
        // those — see SectionController::index()'s assigned_subjects_
        // count withCount() for the exact same rule applied there).
        $totalCount = $section->sectionSubjects()->count();
        $assignedCount = $section->sectionSubjects()
            ->where(function ($query) {
                $query->where(function ($inner) {
                    $inner->whereNotNull('faculty_id')
                        ->whereNotNull('room_id')
                        ->whereNotNull('days')
                        ->whereNotNull('start_time')
                        ->whereNotNull('end_time');
                })->orWhereHas('subject', function ($subjectQuery) {
                    $subjectQuery->where('subject_type', 'practicum');
                });
            })
            ->count();

        if ($totalCount === 0 || $assignedCount < $totalCount) {
            return back()->with('error', "Section {$section->section_code} isn't fully scheduled yet — every subject needs Faculty, Room, Days, and Time before its schedule can be finalized.");
        }

        // TRANSACTIONAL FINALIZE + NOTIFY (spec Section 10) — the lock
        // write, the audit row, and the Dean/OIC notification all
        // commit together or not at all. If anything inside throws,
        // the whole thing rolls back: the Section is never left
        // locked without its audit/notification, and a notification
        // is never created for a finalize that didn't actually stick.
        DB::transaction(function () use ($request, $section, $notifications) {
            // NOTE: is_finalized/finalized_at/finalized_by are
            // deliberately excluded from Section::$fillable (see the
            // model's docblock) so a generic UpdateSectionRequest can
            // never flip them — but that same guard means a plain
            // ->update() here would silently discard all three
            // (Laravel drops non-fillable attributes on mass
            // assignment without erroring). forceFill() is the
            // correct escape hatch for this one legitimate,
            // controller-owned write path — same reasoning as
            // schedule_version being off-limits to mass assignment
            // (ScheduleConflictService::bumpScheduleVersion() instead
            // uses increment(), which bypasses $fillable the same way).
            $section->forceFill([
                'is_finalized' => true,
                'finalized_at' => now(),
                'finalized_by' => $request->user()->id,
            ])->save();

            $notifications->finalized($section, $request->user());
        });

        return back()->with('success', "Section {$section->section_code}'s schedule has been finalized.");
    }

    /**
     * SECTION-LEVEL SCHEDULE FINALIZATION.
     *
     * Reverses finalize() — Registrar/Admin only (SectionPolicy::
     * unlockSchedule()), deliberately not the Dean/OIC who finalized
     * it. Requires a short reason so there's an audit trail of why a
     * "done" schedule was reopened; this becomes part of the
     * flash-message success text today, and is the natural place to
     * hook in an activity log entry later using the same pattern as
     * term_college_finalizations.
     */
    public function unlock(Request $request, Section $section, NotificationService $notifications): RedirectResponse
    {
        $this->authorize('unlockSchedule', $section);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // TRANSACTIONAL UNLOCK + NOTIFY — same reasoning as finalize()
        // above (spec Section 10): unlock write, audit row, and
        // Dean/OIC notification all commit together or not at all.
        DB::transaction(function () use ($request, $section, $notifications, $validated) {
            // Same forceFill() reasoning as finalize() above —
            // is_finalized/finalized_at/finalized_by are guarded out
            // of $fillable, so a plain ->update() here would silently
            // no-op instead of unlocking the section.
            $section->forceFill([
                'is_finalized' => false,
                'finalized_at' => null,
                'finalized_by' => null,
            ])->save();

            $notifications->unlocked($section, $request->user(), $validated['reason']);
        });

        return back()->with(
            'success',
            "Section {$section->section_code}'s schedule has been unlocked. Reason: {$validated['reason']}"
        );
    }

    /**
     * Applies the "Scheduling Status" filter (spec: College/Year
     * Level/Scheduling Status filters on the Sections page) directly
     * against the database — never against frontend-computed badges.
     *
     * Reuses the exact "assigned" definition already used by
     * index()'s assigned_subjects_count withCount() and by
     * finalize()'s completeness check, and the 'Conflict' status
     * SectionSubject rows already carry (see the section_subjects
     * migration) — no second/competing definition of these states is
     * introduced here.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Section>  $query
     */
    private function applySchedulingStatusFilter($query, string $status): void
    {
        $hasConflict = fn ($inner) => $inner->where('status', 'Conflict');

        switch ($status) {
            case 'no_subjects':
                $query->whereDoesntHave('sectionSubjects');
                break;

            case 'finalized':
                $query->where('is_finalized', true);
                break;

            case 'needs_attention':
                $query->where('is_finalized', false)
                    ->whereHas('sectionSubjects', $hasConflict);
                break;

            case 'fully_scheduled':
                $query->where('is_finalized', false)
                    ->whereHas('sectionSubjects')
                    ->whereDoesntHave('sectionSubjects', $hasConflict)
                    ->whereDoesntHave('sectionSubjects', fn ($inner) => $this->unassignedSectionSubjectQuery($inner));
                break;

            case 'in_progress':
            default:
                $query->where('is_finalized', false)
                    ->whereHas('sectionSubjects')
                    ->whereDoesntHave('sectionSubjects', $hasConflict)
                    ->whereHas('sectionSubjects', fn ($inner) => $this->unassignedSectionSubjectQuery($inner));
                break;
        }
    }

    /**
     * The inverse of index()'s assigned_subjects_count condition: a
     * SectionSubject row that is NOT fully assigned (missing Faculty/
     * Room/Days/Start/End) and is NOT a Practicum/OJT row (which never
     * needs those fields — see Subject::isPracticum()).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\SectionSubject>  $query
     */
    private function unassignedSectionSubjectQuery($query)
    {
        return $query->where(function ($inner) {
            $inner->whereNull('faculty_id')
                ->orWhereNull('room_id')
                ->orWhereNull('days')
                ->orWhereNull('start_time')
                ->orWhereNull('end_time');
        })->whereDoesntHave('subject', function ($subjectQuery) {
            $subjectQuery->where('subject_type', 'practicum');
        });
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
     * Build the Sections page's Academic Term filter dropdown: every
     * real Academic Term on record (Active, Inactive, or Archived),
     * each resolved to the "{academic_year}|{semester}" value used to
     * filter the Sections query, plus a leading "All Terms" option.
     *
     * Terms that can't be resolved to a Section-matching value (see
     * AcademicTerm::sectionSemesterValue()) are skipped — they'd never
     * match any Section anyway, so listing them would just be a dead
     * filter option.
     *
     * @return list<array{value: string, label: string, status: string}>
     */
    private function termFilterOptions(): array
    {
        $options = [
            ['value' => 'all', 'label' => 'All Terms', 'status' => null],
        ];

        AcademicTerm::query()
            ->with(['schoolYear:id,name', 'semester:id,name'])
            ->orderByDesc('id')
            ->get()
            ->each(function (AcademicTerm $term) use (&$options) {
                $semesterValue = $term->sectionSemesterValue();

                if (! $term->schoolYear || ! $semesterValue) {
                    return;
                }

                $options[] = [
                    'value' => "{$term->schoolYear->name}|{$semesterValue}",
                    'label' => "{$term->schoolYear->name} · {$term->semester->name}",
                    'status' => $term->status,
                ];
            });

        return $options;
    }

    /**
     * Build the Add/Edit Section form's Academic Year + Semester
     * options from real AcademicTerm records — replaces the old
     * rolling-range generator, which let a Section be created for a
     * School Year/Semester combination with no AcademicTerm at all
     * (no Scheduling Preferences, no class hours/days configured),
     * orphaned from the Sections page's own term filter
     * (termFilterOptions() above, which only ever lists real
     * AcademicTerm records).
     *
     * Shaped as a flat list of { academic_year, semester, status }
     * pairs (via AcademicTerm::sectionSemesterValue(), never a raw
     * string compare — see that method's docblock) rather than a
     * nested structure, mirroring how this page already hands the
     * frontend a flat `curriculums` list (each row carrying its own
     * major_id) for the Vue side to filter client-side — see
     * RoomController's `departments` (each carrying college_id) for
     * the same pattern elsewhere in the app. Vue derives both the
     * Academic Year dropdown (unique academic_year values) and the
     * Semester dropdown (filtered to the selected Academic Year) from
     * this one list.
     *
     * Archived terms are excluded — creating a brand-new Section under
     * an Archived term doesn't make sense, since that term is done and
     * no longer meant to receive new scheduling data. A Section that
     * already exists under a term which has since been Archived is
     * unaffected by this exclusion — see the frontend's
     * currentTermOption fallback, which keeps that Section's own
     * Academic Year/Semester selectable on its own Edit form even
     * though it won't appear when creating a new one.
     *
     * Terms that can't be resolved to a Section-matching semester
     * value (see sectionSemesterValue()'s docblock) are skipped, same
     * as termFilterOptions() — they'd never be selectable anyway.
     *
     * @return list<array{academic_year: string, semester: string, status: string}>
     */
    private function academicTermSectionOptions(): array
    {
        $options = [];

        AcademicTerm::query()
            ->where('status', '!=', 'Archived')
            ->with(['schoolYear:id,name', 'semester:id,name'])
            ->orderByDesc('id')
            ->get()
            ->each(function (AcademicTerm $term) use (&$options) {
                $semesterValue = $term->sectionSemesterValue();

                if (! $term->schoolYear || ! $semesterValue) {
                    return;
                }

                $options[] = [
                    'academic_year' => $term->schoolYear->name,
                    'semester' => $semesterValue,
                    'status' => $term->status,
                ];
            });

        return $options;
    }
}