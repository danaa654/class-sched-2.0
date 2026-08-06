<?php

namespace App\Http\Controllers;

use App\Http\Requests\BatchUpdateSectionSubjectScheduleRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\StoreSectionSubjectRequest;
use App\Http\Requests\UpdateSectionSubjectScheduleRequest;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\Faculty;
use App\Models\Major;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;
use App\Services\AutoScheduleService;
use App\Services\EDPCodeService;
use App\Services\RecommendationService;
use App\Services\ScheduleConflictService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SectionSubjectController extends Controller
{
    public function __construct(
        private readonly EDPCodeService $edpCodeService,
        private readonly ScheduleConflictService $conflictService,
        private readonly RecommendationService $recommendationService,
        private readonly AutoScheduleService $autoScheduleService
    ) {
    }

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
     * Display the Section Subjects page for a single Section — this IS
     * the Registrar's scheduling workspace. Every subject assigned to
     * the section is shown with its schedule slot (Faculty, Room,
     * Days, Start/End Time) editable inline; nothing is auto-assigned,
     * those fields stay empty/Draft until the Registrar fills them in
     * (manually, or later via AI recommendations) — see
     * updateSchedule().
     */
    public function show(Request $request, Section $section): Response
    {
        $section->load(['major:id,name,code', 'curriculum:id,code,name,major_id']);

        $search = trim((string) $request->query('subject_search', ''));

        $sectionSubjects = $section->sectionSubjects()
            ->with(['subject', 'faculty', 'room'])
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

        // Every active Curriculum belonging to this Section's own Major,
        // for the "Load From Curriculum" tab's Curriculum dropdown.
        // Curriculum.major_id is required (one Curriculum = one Major),
        // so this is a hard restriction, not a client-side filter —
        // a BSIT section will never see a BSED curriculum to pick from.
        $curriculums = Curriculum::query()
            ->where('status', 'Active')
            ->where('major_id', $section->major_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'major_id']);

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

        // Every active Faculty member, with the Subjects they're
        // qualified to teach (Teaching Qualifications module). Sent in
        // full so the scheduling table can filter the Faculty dropdown
        // per-row client-side without a round trip per cell. General
        // Education Faculty are qualified for every "General Education"
        // subject regardless of their explicit pivot rows.
        $activeFaculty = Faculty::query()
            ->where('status', 'Active')
            ->with('subjects:id')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'college_id'])
            ->map(fn (Faculty $faculty) => [
                'id' => $faculty->id,
                'full_name' => $faculty->full_name,
                'faculty_category' => $faculty->faculty_category,
                'qualified_subject_ids' => $faculty->subjects->pluck('id'),
            ]);

        $activeRooms = Room::query()
            ->where('status', 'Active')
            ->orderBy('room_code')
            ->get(['id', 'room_code', 'room_name', 'room_type', 'capacity']);

        return Inertia::render('Scheduling/SectionSubjects/Show', [
            'section' => $section,
            'sectionSubjects' => $sectionSubjects,
            'filters' => ['subject_search' => $search],
            'availableSubjects' => $availableSubjects,
            // Scheduling table (Faculty/Room/Days/Time cells).
            'activeFaculty' => $activeFaculty,
            'activeRooms' => $activeRooms,
            'curriculums' => $curriculums,
            'yearLevelMap' => self::YEAR_LEVEL_MAP,
            'sectionYearLevel' => self::YEAR_LEVEL_MAP[$section->year_level] ?? null,
            'sectionSemester' => $section->semester,
            // Section Information tab (Tab 1) options.
            'activeMajors' => Major::query()->where('status', 'Active')->orderBy('name')->get(['id', 'name', 'code']),
            'yearLevels' => StoreSectionRequest::YEAR_LEVELS,
            'semesterOptions' => StoreSectionRequest::SEMESTERS,
            'academicYears' => $this->academicYearOptions(),
        ]);
    }

    /**
     * Inline "spreadsheet" schedule update for a single Subject row on
     * the workspace — Faculty, Room, Days, Start/End Time, or Capacity.
     * The frontend auto-saves one field at a time as the user edits a
     * cell.
     *
     * Runs the same conflict checks the scheduling engine relies on:
     * a Faculty or Room already booked on an overlapping Day/Time slot
     * elsewhere blocks the save. On success the row's Status is
     * recomputed (Draft / Scheduled / Conflict) and the fresh row is
     * returned so the frontend can update in place without a full
     * page reload.
     */
    public function updateSchedule(
        UpdateSectionSubjectScheduleRequest $request,
        Section $section,
        SectionSubject $subject
    ): \Illuminate\Http\JsonResponse {
        abort_unless($subject->section_id === $section->id, 404);

        $validated = $request->validated();

        // Merge the incoming (possibly partial) edit onto the row's
        // current values so conflict-checking always evaluates the
        // row's *resulting* Day/Time/Faculty/Room, not just the one
        // field that changed.
        $facultyId = array_key_exists('faculty_id', $validated) ? $validated['faculty_id'] : $subject->faculty_id;
        $roomId = array_key_exists('room_id', $validated) ? $validated['room_id'] : $subject->room_id;
        $days = array_key_exists('days', $validated) ? implode(',', $validated['days'] ?? []) : $subject->days;
        $startTime = array_key_exists('start_time', $validated) ? $validated['start_time'] : $subject->start_time;
        $endTime = array_key_exists('end_time', $validated) ? $validated['end_time'] : $subject->end_time;
        $capacity = array_key_exists('capacity', $validated) ? $validated['capacity'] : $subject->capacity;

        $errors = [];

        // Room Capacity Warning — Section Capacity > Room Capacity is not
        // a hard block, but the Registrar must explicitly confirm before
        // it's allowed to save (see UpdateSectionSubjectScheduleRequest).
        if ($roomId && $capacity) {
            $room = Room::find($roomId);
            if ($room && $capacity > $room->capacity && ! $request->boolean('capacity_confirmed')) {
                $errors['capacity'] = "Section Capacity ({$capacity}) exceeds this room's capacity ({$room->capacity}). "
                    .'Confirm to save anyway.';
            }
        }

        $dayTokens = array_filter(explode(',', (string) $days));

        // Section / Faculty / Room / Time-overlap conflict checks all
        // live in ScheduleConflictService — never duplicated here — so
        // the manual workspace and the future Genetic Algorithm scheduler
        // both validate against the exact same rules.
        $errors = array_merge($errors, $this->conflictService->validate(
            [
                'section_id' => $section->id,
                'faculty_id' => $facultyId,
                'room_id' => $roomId,
                'days' => $dayTokens,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ],
            $subject->id
        ));

        if (! empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        $status = 'Draft';
        if ($facultyId && $roomId && ! empty($dayTokens) && $startTime && $endTime) {
            $status = 'Scheduled';
        }

        $subject->update([
            'faculty_id' => $facultyId,
            'room_id' => $roomId,
            'days' => $days ?: null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'capacity' => $capacity,
            'status' => $status,
            // A hand-edited row is no longer purely "Auto Generated" —
            // untag it so Clear Generated Schedule / Regenerate never
            // touch what the Registrar just chose themselves.
            'is_auto_generated' => false,
            'auto_generated_meta' => null,
        ]);

        return response()->json([
            'sectionSubject' => $subject->fresh(['subject', 'faculty', 'room']),
            'message' => 'Schedule updated.',
        ]);
    }

    /**
     * Smart Assignment Recommendation Engine (Prompt 8.6).
     *
     * Returns ranked Faculty, Room, and Time suggestions for one
     * scheduling workspace row — never assigns anything itself. All
     * of the actual recommendation logic lives in RecommendationService
     * (reused later by the Genetic Algorithm), never here.
     */
    public function recommend(Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        return response()->json($this->recommendationService->recommend($subject));
    }

    /**
     * Faculty Recommendation Selector — Prompt 8.11.
     *
     * Backs the interactive dropdown on the Auto Generate review
     * panel. With no `search` query param it just returns the
     * recommended pool (identical to what Auto Generate itself
     * picked from); with one, it also returns a global search across
     * every Active faculty member so the Registrar can intentionally
     * pick someone outside the recommended list.
     */
    public function facultyOptions(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $subject->loadMissing(['subject', 'section.major.department']);

        return response()->json($this->recommendationService->facultyOptionsForSelector(
            $subject->subject,
            $subject->section,
            $subject,
            $request->query('search')
        ));
    }

    /**
     * Faculty Recommendation Selector — Manual Override (Prompt 8.11).
     *
     * Applies the Registrar's faculty pick to this row IMMEDIATELY
     * (no need to leave or close the Auto Generate modal) and returns
     * the recomputed Live Score/badge/reasons for it — including a
     * "Manual Override" explanation when the pick falls outside every
     * recommended tier. Room/Days/Time are left exactly as Auto
     * Generate produced them; only Faculty and its scoring metadata
     * change. The row keeps is_auto_generated = true / Status
     * 'Draft' — it's still reviewed by "Accept All & Save" like the
     * rest of the panel, it just now reflects the Registrar's choice.
     */
    public function overrideFaculty(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $validated = $request->validate([
            'faculty_id' => ['required', 'integer', 'exists:faculties,id'],
        ]);

        $subject->loadMissing(['subject', 'section.major.department']);

        $faculty = Faculty::query()->where('status', 'Active')->findOrFail($validated['faculty_id']);

        $scored = $this->recommendationService->scoreArbitraryFaculty(
            $faculty,
            $subject->subject,
            $subject->section,
            $subject
        );

        $meta = $subject->auto_generated_meta ?? [];
        $meta['faculty'] = [
            'id' => $scored['id'],
            'name' => $scored['name'],
            'score' => $scored['score'],
            'confidence' => $scored['confidence'],
            'reasons' => $scored['reasons'],
            'tier' => $scored['tier'],
            'badge' => $scored['badge'],
            'manual_override' => $scored['manual_override'],
            'override_reason' => $scored['override_reason'],
            'selected_by_college_match' => $scored['selected_by_college_match'],
        ];

        if (isset($meta['room']['score'], $meta['time']['score'])) {
            $meta['overall_score'] = (int) round(($scored['score'] + $meta['room']['score'] + $meta['time']['score']) / 3);
        }

        $subject->update([
            'faculty_id' => $faculty->id,
            'auto_generated_meta' => $meta,
        ]);

        return response()->json([
            'section_subject_id' => $subject->id,
            'faculty' => $scored,
            'overall_score' => $meta['overall_score'] ?? $scored['score'],
        ]);
    }

    /**
     * Room Recommendation Selector — options list (recommended pool +
     * global search, scored via RecommendationService::roomOptionsForSelector()).
     */
    public function roomOptions(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $subject->loadMissing(['subject', 'section.major.department']);

        return response()->json($this->recommendationService->roomOptionsForSelector(
            $subject->subject,
            $subject->section,
            $subject,
            $request->query('search')
        ));
    }

    /**
     * Room Recommendation Selector — Manual Override.
     *
     * Applies the Registrar's room pick to this row IMMEDIATELY (no
     * need to leave or close the Auto Generate modal) and returns the
     * recomputed Live Score/badge/reasons for it — including a
     * "Manual Override" explanation when the pick falls outside the
     * hard-filtered recommended pool (wrong type, too small, occupied,
     * or a different College). Faculty/Days/Time are left exactly as
     * Auto Generate produced them; only Room and its scoring metadata
     * change. The row keeps is_auto_generated = true / Status 'Draft'
     * — it's still reviewed by "Accept All & Save" like the rest of
     * the panel, it just now reflects the Registrar's choice.
     */
    public function overrideRoom(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
        ]);

        $subject->loadMissing(['subject', 'section.major.department']);

        $room = Room::query()->where('status', 'Active')->findOrFail($validated['room_id']);

        $scored = $this->recommendationService->scoreArbitraryRoom(
            $room,
            $subject->subject,
            $subject->section,
            $subject
        );

        $meta = $subject->auto_generated_meta ?? [];
        $meta['room'] = [
            'id' => $scored['id'],
            'name' => $scored['name'],
            'score' => $scored['score'],
            'confidence' => $scored['confidence'],
            'reasons' => $scored['reasons'],
            'match_tier' => $scored['match_tier'],
            'badge' => $scored['badge'],
            'manual_override' => $scored['manual_override'],
            'override_reason' => $scored['override_reason'],
        ];

        if (isset($meta['faculty']['score'], $meta['time']['score'])) {
            $meta['overall_score'] = (int) round(($meta['faculty']['score'] + $scored['score'] + $meta['time']['score']) / 3);
        }

        $subject->update([
            'room_id' => $room->id,
            'auto_generated_meta' => $meta,
        ]);

        return response()->json([
            'section_subject_id' => $subject->id,
            'room' => $scored,
            'overall_score' => $meta['overall_score'] ?? $scored['score'],
        ]);
    }

    /**
     * Time Recommendation Selector — Manual Override.
     *
     * Applies the Registrar's Days/Start/End pick to this row
     * IMMEDIATELY (no need to leave or close the Auto Generate modal)
     * and returns the recomputed Live Score/reasons for it — same
     * "click to edit, apply instantly" flow Faculty/Room already have.
     * A conflicting or off-pattern (e.g. 3x/week when the subject
     * expects 2x/week) time is never silently rejected here — it's
     * still applied and flagged as a Manual Override, exactly like an
     * out-of-pool Faculty/Room pick, so the Registrar stays in
     * control and can see why it's flagged before deciding to keep it.
     * Faculty/Room are left exactly as Auto Generate produced them;
     * only Days/Start/End and the Time scoring metadata change.
     */
    public function overrideTime(Request $request, Section $section, SectionSubject $subject): JsonResponse
    {
        abort_unless($subject->section_id === $section->id, 404);

        $validated = $request->validate([
            'days' => ['required', 'array', 'min:1', 'max:3'],
            'days.*' => ['required', 'string', 'in:Mon,Tue,Wed,Thu,Fri,Sat,Sun'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        $subject->loadMissing(['subject', 'section.major.department']);

        $scored = $this->recommendationService->scoreArbitraryTime(
            $validated['days'],
            $validated['start_time'],
            $validated['end_time'],
            $subject->subject,
            $subject->section,
            $subject->faculty_id,
            $subject->room_id,
            $subject
        );

        $meta = $subject->auto_generated_meta ?? [];
        $meta['time'] = [
            'days' => $scored['days'],
            'start_time' => $scored['start_time'],
            'end_time' => $scored['end_time'],
            'score' => $scored['score'],
            'confidence' => $scored['confidence'],
            'reasons' => $scored['reasons'],
            'manual_override' => $scored['manual_override'],
            'override_reason' => $scored['override_reason'],
        ];

        if (isset($meta['faculty']['score'], $meta['room']['score'])) {
            $meta['overall_score'] = (int) round(($meta['faculty']['score'] + $meta['room']['score'] + $scored['score']) / 3);
        }

        $subject->update([
            'days' => implode(',', $scored['days']),
            'start_time' => $scored['start_time'],
            'end_time' => $scored['end_time'],
            'auto_generated_meta' => $meta,
        ]);

        return response()->json([
            'section_subject_id' => $subject->id,
            'time' => $scored,
            'overall_score' => $meta['overall_score'] ?? $scored['score'],
        ]);
    }

    /**
     * "⚡ Auto Generate Schedule" (Prompt 8.9).
     *
     * Runs AutoScheduleService for every currently unscheduled subject
     * in this Section — Faculty, Room, and Time are chosen and WRITTEN
     * immediately (Status stays 'Draft', is_auto_generated = true) so
     * later subjects in the same run correctly see earlier ones as
     * conflicts via the same ScheduleConflictService the manual
     * workspace uses. Nothing here ever touches a row that already has
     * a Faculty/Room/Days/Time — those are left completely alone.
     *
     * The Registrar still reviews the result and must click
     * "Save Schedule" to finalize it (or "Clear Generated Schedule" /
     * "Regenerate" to discard/retry) — see updateSchedule() and
     * batchUpdateSchedule(), which strip the is_auto_generated flag the
     * moment a row is actually saved by hand.
     */
    public function autoGenerate(Section $section): JsonResponse
    {
        $summary = $this->autoScheduleService->generate($section);

        return response()->json([
            ...$summary,
            'sectionSubjects' => $section->sectionSubjects()->with(['subject', 'faculty', 'room'])->get(),
        ]);
    }

    /**
     * "Regenerate" — discards every previously Auto Generated row
     * (never manually-assigned ones) and runs Auto Generate again from
     * a clean slate.
     */
    public function regenerateSchedule(Section $section): JsonResponse
    {
        $summary = $this->autoScheduleService->regenerate($section);

        return response()->json([
            ...$summary,
            'sectionSubjects' => $section->sectionSubjects()->with(['subject', 'faculty', 'room'])->get(),
        ]);
    }

    /**
     * "Clear Generated Schedule" — reverts every Auto Generated row
     * back to an empty schedule slot. Manually assigned rows are never
     * touched.
     */
    public function clearAutoGenerated(Section $section): JsonResponse
    {
        $cleared = $this->autoScheduleService->clear($section);

        return response()->json([
            'cleared' => $cleared,
            'message' => $cleared > 0
                ? "{$cleared} auto-generated ".($cleared === 1 ? 'schedule was' : 'schedules were')." cleared."
                : 'No auto-generated schedules to clear.',
            'sectionSubjects' => $section->sectionSubjects()->with(['subject', 'faculty', 'room'])->get(),
        ]);
    }

    /**
     * "Save Schedule" — the manual scheduling workspace's batch save.
     * The Registrar edits Faculty/Room/Days/Start/End Time across as
     * many subject rows as they like *locally* in the table (no
     * per-cell auto-save), then submits everything at once here.
     *
     * Every row is validated with the same Faculty/Room conflict
     * checks updateSchedule() runs for a single cell — including
     * against other rows in this same batch, since rows are saved
     * one at a time inside the transaction and later rows can see
     * earlier rows' just-saved values on the same connection. If
     * ANY row fails validation, the whole transaction is rolled
     * back — nothing is saved — and every row's errors are returned
     * together so the Registrar can fix them all at once. Only when
     * every row passes does the transaction commit.
     */
    public function batchUpdateSchedule(
        BatchUpdateSectionSubjectScheduleRequest $request,
        Section $section
    ): \Illuminate\Http\JsonResponse {
        $rows = $request->validated()['rows'];

        // Guard against rows that belong to a different Section
        // slipping in (e.g. a stale tab); exists:section_subjects,id
        // in the FormRequest only checks the row exists at all.
        $rowIds = collect($rows)->pluck('id');
        $validRowIds = $section->sectionSubjects()->whereIn('id', $rowIds)->pluck('id');
        $foreignIds = $rowIds->diff($validRowIds);

        if ($foreignIds->isNotEmpty()) {
            return response()->json([
                'message' => 'One or more rows no longer belong to this section. Please refresh and try again.',
            ], 422);
        }

        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $rowData) {
                $subject = SectionSubject::query()->where('id', $rowData['id'])->lockForUpdate()->first();

                $facultyId = $rowData['faculty_id'] ?? null;
                $roomId = $rowData['room_id'] ?? null;
                $days = ! empty($rowData['days']) ? implode(',', $rowData['days']) : null;
                $startTime = $rowData['start_time'] ?? null;
                $endTime = $rowData['end_time'] ?? null;
                $capacity = $rowData['capacity'] ?? $subject->capacity;

                $rowErrors = [];

                // Room Capacity Warning — not a hard block, but the
                // Registrar must explicitly confirm this row before it's
                // allowed to save (see BatchUpdateSectionSubjectScheduleRequest).
                if ($roomId && $capacity) {
                    $room = Room::find($roomId);
                    if ($room && $capacity > $room->capacity && empty($rowData['capacity_confirmed'])) {
                        $rowErrors['capacity'] = "Section Capacity ({$capacity}) exceeds this room's capacity ({$room->capacity}). "
                            .'Confirm to save anyway.';
                    }
                }

                $dayTokens = array_filter(explode(',', (string) $days));

                // Same shared ScheduleConflictService the single-cell
                // save uses above — kept identical so batch saves can
                // never disagree with per-cell saves about what counts
                // as a conflict.
                $rowErrors = array_merge($rowErrors, $this->conflictService->validate(
                    [
                        'section_id' => $section->id,
                        'faculty_id' => $facultyId,
                        'room_id' => $roomId,
                        'days' => $dayTokens,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                    ],
                    $subject->id
                ));

                if (! empty($rowErrors)) {
                    // Keyed by SectionSubject id so the frontend can
                    // map each error back to the exact row.
                    $errors[$subject->id] = $rowErrors;

                    continue;
                }

                $status = 'Draft';
                if ($facultyId && $roomId && ! empty($dayTokens) && $startTime && $endTime) {
                    $status = 'Scheduled';
                }

                $subject->update([
                    'faculty_id' => $facultyId,
                    'room_id' => $roomId,
                    'days' => $days ?: null,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'capacity' => $capacity,
                    'status' => $status,
                    // "Save Schedule" finalizes any Auto Generated rows
                    // it saves — once saved they're a normal schedule,
                    // no longer a pending suggestion to clear/regenerate.
                    'is_auto_generated' => false,
                    'auto_generated_meta' => null,
                ]);
            }

            if (! empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'errors' => $errors,
                    'message' => 'Some rows have scheduling conflicts. Nothing was saved — fix the highlighted rows and try again.',
                ], 422);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to save the schedule. Please try again.',
            ], 500);
        }

        $fresh = $section->sectionSubjects()
            ->with(['subject', 'faculty', 'room'])
            ->whereIn('id', $rowIds)
            ->get();

        return response()->json([
            'sectionSubjects' => $fresh,
            'message' => 'Schedule saved successfully.',
        ]);
    }

    /**
     * Build a rolling list of Academic Year options (e.g. "2026-2027"),
     * matching SectionController's own generator so both places offer
     * the same choices.
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

    /**
     * "Generate Curriculum Subjects" (Tab 2) — automatically loads every
     * Subject from the Section's own Curriculum, Year Level, and
     * Semester. Subjects already assigned to the Section are skipped,
     * so this is safe to click repeatedly (additive, never destructive,
     * never duplicates).
     */
    public function generateCurriculumSubjects(Section $section): RedirectResponse
    {
        $mappedYearLevel = self::YEAR_LEVEL_MAP[$section->year_level] ?? null;

        if (! $mappedYearLevel) {
            return back()->with('error', 'This section has no recognized year level to generate subjects from.');
        }

        $placedSubjectIds = $section->sectionSubjects()->pluck('subject_id');

        $subjectIds = CurriculumItem::query()
            ->where('curriculum_id', $section->curriculum_id)
            ->where('year_level', $mappedYearLevel)
            ->where('semester', $section->semester)
            ->whereNotIn('subject_id', $placedSubjectIds)
            ->pluck('subject_id');

        if ($subjectIds->isEmpty()) {
            return back()->with('error', 'No new subjects found for this section\'s curriculum, year level, and semester.');
        }

        $section->loadMissing('major');

        foreach ($subjectIds as $subjectId) {
            $sectionSubject = SectionSubject::create([
                'section_id' => $section->id,
                'subject_id' => $subjectId,
                'source' => 'Curriculum',
                'capacity' => $section->estimated_students,
            ]);

            // EDP Code is minted the moment a subject is placed into the
            // section — it doesn't wait for scheduling. No-op if the row
            // somehow already has one.
            $sectionSubject->setRelation('section', $section);
            $this->edpCodeService->generateForSectionSubject($sectionSubject);
        }

        $count = $subjectIds->count();

        return back()->with('success', "{$count} " . ($count === 1 ? 'subject' : 'subjects') . ' generated from the curriculum.');
    }

    /**
     * "Preview Subjects" (Load From Curriculum tab) — every Subject on
     * the given Curriculum for the given Year Level and Semester that
     * isn't already assigned to this Section. Curriculum, Year Level,
     * and Semester together pin the preview to one specific
     * major/year/semester offering, since Curriculum.major_id fixes
     * the major and CurriculumItem.year_level/semester fix the rest.
     */
    public function curriculumPreview(Request $request, Section $section): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'curriculum_id' => ['required', 'integer', 'exists:curriculums,id'],
            'year_level' => ['required', 'string'],
            'semester' => ['required', 'string'],
        ]);

        $placedSubjectIds = $section->sectionSubjects()->pluck('subject_id');

        $subjects = CurriculumItem::query()
            ->where('curriculum_id', $validated['curriculum_id'])
            ->where('year_level', $validated['year_level'])
            ->where('semester', $validated['semester'])
            ->whereNotIn('subject_id', $placedSubjectIds)
            ->with('subject:id,subject_code,subject_title,category,units')
            ->get()
            ->pluck('subject')
            ->filter()
            ->map(fn (Subject $subject) => [
                'id' => $subject->id,
                'subject_code' => $subject->subject_code,
                'subject_title' => $subject->subject_title,
                'category' => $subject->category,
                'units' => $subject->units,
            ])
            ->sortBy('subject_code')
            ->values();

        return response()->json(['subjects' => $subjects]);
    }

    /**
     * Add one or more Subjects to the Section manually — for irregular
     * students, bridging subjects, replacement subjects, or
     * cross-enrolled subjects. Duplicate subjects within the Section
     * are rejected by StoreSectionSubjectRequest.
     */
    public function store(StoreSectionSubjectRequest $request, Section $section): RedirectResponse
    {
        $validated = $request->validated();

        foreach ($validated['subject_ids'] as $subjectId) {
            $sectionSubject = SectionSubject::create([
                'section_id' => $section->id,
                'subject_id' => $subjectId,
                'source' => $validated['source'],
                // New subjects always start with an empty schedule slot.
                // Faculty, Room, Days, and Time are assigned later by the
                // scheduling engine — never automatically here. Capacity
                // defaults to the Section's own Estimated Students unless
                // the caller explicitly set one.
                'capacity' => $validated['capacity'] ?? $section->estimated_students,
                'status' => 'Draft',
            ]);

            // EDP Code is minted the moment a subject is placed into the
            // section — it doesn't wait for scheduling. No-op if the row
            // somehow already has one.
            $sectionSubject->setRelation('section', $section->loadMissing('major'));
            $this->edpCodeService->generateForSectionSubject($sectionSubject);
        }

        $count = count($validated['subject_ids']);

        return back()->with('success', $count === 1 ? 'Subject added to the section.' : "{$count} subjects added to the section.");
    }

    /**
     * Remove a Subject from the Section. Only the placement
     * (SectionSubject) is deleted — the master Subject record, and any
     * other Section it belongs to, is untouched.
     */
    public function destroy(Section $section, Subject $subject): RedirectResponse
    {
        $section->sectionSubjects()->where('subject_id', $subject->id)->delete();

        return back()->with('success', 'Subject removed from the section.');
    }
}