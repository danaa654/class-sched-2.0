<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;

/**
 * Smart Assignment Recommendation Engine (Prompt 8.6).
 *
 * Given a Subject placed into a Section (a SectionSubject row on the
 * scheduling workspace), this service ranks the best candidate
 * Faculty, Room, and Time slot for it. It NEVER assigns anything —
 * it only returns ranked suggestions for the Registrar (or, later,
 * the Genetic Algorithm) to choose from.
 *
 * Kept framework-thin and free of HTTP concerns — same reasoning as
 * ScheduleConflictService — so the future Genetic Algorithm scheduler
 * can call recommend()/recommendFaculty()/recommendRooms()/
 * recommendTimes() directly when searching for candidate assignments,
 * exactly the same way the manual workspace does here.
 */
class RecommendationService
{
    /**
     * Maps the short Day tokens used throughout the scheduling
     * workspace (days column, dayOptions in Show.vue) to the full
     * day names FacultyAvailability.day_of_week stores.
     *
     * @var array<string, string>
     */
    private const DAY_MAP = [
        'Mon' => 'Monday',
        'Tue' => 'Tuesday',
        'Wed' => 'Wednesday',
        'Thu' => 'Thursday',
        'Fri' => 'Friday',
        'Sat' => 'Saturday',
        'Sun' => 'Sunday',
    ];

    /**
     * Day-combination patterns tried when hunting for an open Time
     * slot, tried in order. Mirrors the workspace's own dayPresets.
     *
     * @var list<list<string>>
     */
    private const DAY_PATTERNS = [
        ['Mon', 'Wed', 'Fri'],
        ['Tue', 'Thu'],
        ['Mon', 'Wed'],
        ['Wed', 'Fri'],
        ['Sat'],
    ];

    /**
     * Candidate start times tried for each Day pattern, earliest
     * first, within a normal academic day.
     *
     * @var list<string>
     */
    private const START_TIMES = ['07:00', '08:00', '09:00', '10:00', '13:00', '14:00', '15:00', '16:00'];

    private const MAX_FACULTY_RESULTS = 5;

    private const MAX_ROOM_RESULTS = 5;

    private const MAX_TIME_RESULTS = 5;

    public function __construct(private readonly ScheduleConflictService $conflictService)
    {
    }

    /**
     * Build the full Faculty + Room + Time recommendation set for one
     * scheduling workspace row. This is what the "Recommendation
     * Panel" on the frontend calls.
     */
    public function recommend(SectionSubject $sectionSubject): array
    {
        $sectionSubject->loadMissing(['subject', 'section.major.department']);

        $subject = $sectionSubject->subject;
        $section = $sectionSubject->section;

        $faculty = $this->recommendFaculty($subject, $section, $sectionSubject);
        $room = $this->recommendRooms($subject, $section, $sectionSubject);

        // The Time recommendation is checked against the *top* Faculty
        // and Room picks (falling back to whatever's already saved on
        // the row) so the suggested slot is actually usable with the
        // other two suggestions, not just theoretically open.
        $topFacultyId = $faculty['recommendations'][0]['id'] ?? $sectionSubject->faculty_id;
        $topRoomId = $room['recommendations'][0]['id'] ?? $sectionSubject->room_id;

        $time = $this->recommendTimes($subject, $section, $topFacultyId, $topRoomId, $sectionSubject);

        // Combined suggestions reuse the Faculty/Room lists already
        // computed above (no re-querying) and reuse recommendTimes()
        // — and therefore ScheduleConflictService — for every
        // Faculty/Room pairing, so a Combined Recommendation can never
        // disagree with the individual Faculty/Room/Time lists or with
        // what happens when the Registrar actually saves.
        $combined = $this->buildCombinedRecommendations(
            $subject, $section, $faculty['recommendations'], $room['recommendations'], $sectionSubject
        );

        return compact('faculty', 'room', 'time', 'combined');
    }

    private const MAX_COMBINED_RESULTS = 5;

    private const COMBINED_CANDIDATE_FACULTY = 3;

    private const COMBINED_CANDIDATE_ROOMS = 3;

    /**
     * COMBINED RECOMMENDATION.
     *
     * Pairs the top-ranked Faculty with the top-ranked Rooms and, for
     * each pairing, asks recommendTimes() (which itself calls
     * ScheduleConflictService) for the single best conflict-free Time
     * slot for that exact Faculty + Room combination. Pairings with no
     * available Time slot are simply dropped — a Combined
     * Recommendation is never shown unless it is fully conflict-free
     * across Faculty, Room, Section, and Time simultaneously.
     */
    private function buildCombinedRecommendations(
        Subject $subject,
        Section $section,
        array $facultyList,
        array $roomList,
        ?SectionSubject $current = null
    ): array {
        if (empty($facultyList) || empty($roomList)) {
            return ['recommendations' => [], 'message' => 'Not enough qualified faculty or available rooms to build combined suggestions.'];
        }

        $combos = [];

        foreach (array_slice($facultyList, 0, self::COMBINED_CANDIDATE_FACULTY) as $facultyRec) {
            foreach (array_slice($roomList, 0, self::COMBINED_CANDIDATE_ROOMS) as $roomRec) {
                $timeResult = $this->recommendTimes($subject, $section, $facultyRec['id'], $roomRec['id'], $current);
                $bestTime = $timeResult['recommendations'][0] ?? null;

                if (! $bestTime) {
                    // No conflict-free slot exists for this exact
                    // Faculty + Room pairing — never surfaced.
                    continue;
                }

                $combinedScore = (int) round(($facultyRec['score'] + $roomRec['score'] + $bestTime['score']) / 3);

                $combos[] = [
                    'faculty' => [
                        'id' => $facultyRec['id'],
                        'name' => $facultyRec['name'],
                        'score' => $facultyRec['score'],
                    ],
                    'room' => [
                        'id' => $roomRec['id'],
                        'name' => $roomRec['name'],
                        'score' => $roomRec['score'],
                    ],
                    'time' => [
                        'days' => $bestTime['days'],
                        'start_time' => $bestTime['start_time'],
                        'end_time' => $bestTime['end_time'],
                        'score' => $bestTime['score'],
                    ],
                    'score' => $combinedScore,
                    'score_max' => 100,
                    'conflict' => null,
                ];
            }
        }

        if (empty($combos)) {
            return ['recommendations' => [], 'message' => 'No fully conflict-free combined schedule could be generated.'];
        }

        // RANK — highest combined score first; ties broken by the
        // higher-scoring Faculty pick (units/qualification carry more
        // weight than Room fit for the Registrar's purposes).
        usort($combos, function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $b['faculty']['score'] <=> $a['faculty']['score'];
        });

        $combos = array_slice($combos, 0, self::MAX_COMBINED_RESULTS);

        foreach ($combos as &$c) {
            $c['confidence'] = $this->confidenceFromScore($c['score']);
        }
        unset($c);

        return ['recommendations' => $combos, 'message' => null];
    }

    /**
     * FACULTY SELECTION — hierarchical strategy (Prompt 8.10):
     *
     *   PRIORITY 1 — Teaching Qualifications
     *     If ANY faculty has been explicitly linked to this Subject via
     *     the Teaching Qualifications module (faculty_subject pivot),
     *     ONLY those faculty are eligible — College is irrelevant here.
     *     Ranked by: Availability, Existing Schedule Conflicts, Lowest
     *     Teaching Load, Full-time before Part-time, Preferred Teaching
     *     Block.
     *
     *   PRIORITY 2 — College Matching (fallback)
     *     Only reached when the Subject has NO Teaching Qualification
     *     configured at all. Eligible faculty = every Active faculty
     *     member whose own College matches the College that owns the
     *     Subject (via Subject -> Major -> Department -> College, the
     *     same chain the Curriculum for that Major belongs to). Ranked
     *     by: Availability, No Schedule Conflicts, Lowest Teaching Load,
     *     Full-time before Part-time. Results are flagged
     *     `selected_by_college_match = true` so the UI can show the
     *     "Selected by College Match" badge.
     *
     *   PRIORITY 3 — Manual Scheduling
     *     No faculty eligible at either tier -> empty recommendations
     *     with message "No qualified or college-matched faculty
     *     available." The Auto Generate engine marks the subject as
     *     requiring manual scheduling; nothing is auto-assigned.
     *
     * Teaching Qualifications are NEVER overridden or bypassed by
     * College Matching — Priority 2 only ever runs when Priority 1
     * returns zero candidates.
     */
    private const TQ_POINTS_QUALIFIED = 25;

    private const TQ_POINTS_AVAILABLE = 25;

    private const TQ_POINTS_NO_CONFLICT = 15;

    private const TQ_POINTS_LOW_LOAD = 15;

    private const TQ_POINTS_FULL_TIME = 10;

    private const TQ_POINTS_PREFERRED_BLOCK = 10;

    private const COLLEGE_POINTS_MATCH = 30;

    private const COLLEGE_POINTS_AVAILABLE = 25;

    private const COLLEGE_POINTS_NO_CONFLICT = 20;

    private const COLLEGE_POINTS_LOW_LOAD = 15;

    private const COLLEGE_POINTS_FULL_TIME = 10;

    /**
     * General Education Match uses the same weight distribution as
     * College Match (they're both Priority 2 fallback tiers) — kept
     * as separate constants only so the two can be tuned
     * independently later without touching each other.
     */
    private const GENED_POINTS_MATCH = 30;

    private const GENED_POINTS_AVAILABLE = 25;

    private const GENED_POINTS_NO_CONFLICT = 20;

    private const GENED_POINTS_LOW_LOAD = 15;

    private const GENED_POINTS_FULL_TIME = 10;

    /**
     * Manual Override carries no "match" points at all — the
     * Registrar has deliberately stepped outside every recommended
     * tier, so the category criterion scores 0 and the Live Score
     * reflects only the faculty's own Availability/Load/Conflict
     * standing.
     */
    private const OVERRIDE_POINTS_MATCH = 0;

    private const OVERRIDE_POINTS_AVAILABLE = 25;

    private const OVERRIDE_POINTS_NO_CONFLICT = 20;

    private const OVERRIDE_POINTS_LOW_LOAD = 15;

    private const OVERRIDE_POINTS_FULL_TIME = 10;

    public function recommendFaculty(Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        // PRIORITY 1 — Teaching Qualifications. Any faculty explicitly
        // linked to this Subject is eligible, full stop; College plays
        // no part in eligibility at this tier.
        $tqFaculty = Faculty::query()
            ->where('status', 'Active')
            ->whereHas('subjects', fn ($q) => $q->where('subjects.id', $subject->id))
            ->with(['subjects:id', 'availabilities'])
            ->get();

        if ($tqFaculty->isNotEmpty()) {
            return [
                'recommendations' => $this->rankFacultyCandidates($tqFaculty, $subject, $current, tier: 'teaching_qualification'),
                'message' => null,
                'tier' => 'teaching_qualification',
            ];
        }

        // PRIORITY 2 — College Matching fallback. Only ever reached
        // when the Subject has no Teaching Qualification configured.
        $collegeId = $this->subjectCollegeId($subject);

        if ($collegeId !== null) {
            $collegeFaculty = Faculty::query()
                ->where('status', 'Active')
                ->where('college_id', $collegeId)
                ->with(['subjects:id', 'availabilities'])
                ->get();

            if ($collegeFaculty->isNotEmpty()) {
                return [
                    'recommendations' => $this->rankFacultyCandidates($collegeFaculty, $subject, $current, tier: 'college_match'),
                    'message' => null,
                    'tier' => 'college_match',
                ];
            }
        }

        // PRIORITY 3 — nobody eligible at either tier. The Registrar
        // (or Auto Generate Schedule) must assign this one manually.
        return [
            'recommendations' => [],
            'message' => 'No qualified or college-matched faculty available.',
            'tier' => null,
        ];
    }

    /**
     * FACULTY RECOMMENDATION SELECTOR (Prompt 8.11).
     *
     * Powers the interactive Faculty selector on the Auto Generate
     * review panel. Returns the same ranked recommendation list
     * recommendFaculty() produces (so "recommended first, AI pick
     * defaulted" always matches what Auto Generate itself chose),
     * plus — when the Registrar types into the search box — a
     * global search across every Active faculty member regardless
     * of College/Teaching Qualification, each scored via
     * scoreArbitraryFaculty() so the dropdown can show a live
     * Recommendation Score even for out-of-pool candidates.
     */
    public function facultyOptionsForSelector(Subject $subject, Section $section, ?SectionSubject $current, ?string $search = null): array
    {
        $recommended = $this->recommendFaculty($subject, $section, $current);

        $searchResults = [];

        if ($search !== null && trim($search) !== '') {
            $recommendedIds = collect($recommended['recommendations'])->pluck('id')->all();

            $matches = Faculty::query()
                ->where('status', 'Active')
                ->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('faculty_id', 'like', "%{$search}%");
                })
                ->whereNotIn('id', $recommendedIds)
                ->with(['subjects:id', 'availabilities', 'college:id,name,short_name'])
                ->limit(20)
                ->get();

            $searchResults = $matches
                ->map(fn (Faculty $faculty) => $this->scoreArbitraryFaculty($faculty, $subject, $section, $current))
                ->sortByDesc('score')
                ->values()
                ->all();
        }

        return [
            'recommended' => $recommended['recommendations'],
            'tier' => $recommended['tier'],
            'message' => $recommended['message'],
            'search_results' => $searchResults,
        ];
    }

    /**
     * Score ANY single faculty member against a Subject/Section, used
     * both for global search results and for recomputing the Live
     * Score the instant the Registrar manually overrides the
     * AI-selected faculty with someone outside the recommended pool.
     *
     * Badge/tier resolution (Prompt 8.11 spec):
     *   - Explicitly linked via Teaching Qualifications -> "Qualified Faculty"
     *   - No Teaching Qualifications anywhere for the Subject, Subject
     *     is Major, faculty's College matches the curriculum's owning
     *     College -> "College Match"
     *   - No Teaching Qualifications, Subject is General Education (or
     *     has no owning College — Minor subjects fall back to the same
     *     GenEd-style pool per Faculty::college_id being null for
     *     GenEd/Minor faculty), faculty has no College of their own -> "General Education Match"
     *   - None of the above -> "Manual Override": the Registrar
     *     deliberately picked someone outside every recommended tier.
     */
    public function scoreArbitraryFaculty(Faculty $faculty, Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        $subject->loadMissing('major.department');
        $faculty->loadMissing(['subjects:id', 'availabilities', 'college:id,name,short_name']);

        $isQualified = $faculty->subjects->contains('id', $subject->id);
        $collegeId = $this->subjectCollegeId($subject);
        $isGenEdSubject = $collegeId === null;
        $isCollegeMatch = ! $isGenEdSubject && $faculty->college_id === $collegeId;
        $isGenEdMatch = $isGenEdSubject && $faculty->college_id === null;

        $tier = match (true) {
            $isQualified => 'teaching_qualification',
            $isCollegeMatch => 'college_match',
            $isGenEdMatch => 'general_education_match',
            default => 'manual_override',
        };

        $badge = match ($tier) {
            'teaching_qualification' => 'Qualified Faculty',
            'college_match' => 'College Match',
            'general_education_match' => 'General Education Match',
            default => 'Manual Override',
        };

        $scored = $this->scoreCandidate($faculty, $subject, $current, $tier);

        $overrideReason = null;
        if ($tier === 'manual_override') {
            $facultyCollege = $faculty->college?->name ?? 'General Education / no College';
            $subjectCollege = $collegeId !== null
                ? ($subject->major?->department?->college?->name ?? 'the curriculum\'s owning College')
                : 'General Education faculty';

            $overrideReason = $collegeId !== null
                ? "Selected faculty belongs to {$facultyCollege}. This subject is recommended for {$subjectCollege}."
                : "Selected faculty belongs to {$facultyCollege}. This subject is a General Education subject and is recommended for General Education faculty.";
        }

        return array_merge($scored, [
            'college' => $faculty->college?->short_name ?? $faculty->college?->name ?? 'General Education',
            'employment_type' => $faculty->employment_type,
            'status' => $faculty->status,
            'badge' => $badge,
            'manual_override' => $tier === 'manual_override',
            'override_reason' => $overrideReason,
        ]);
    }

    /**
     * Shared ranking pass for both Priority 1 (Teaching Qualification)
     * and Priority 2 (College Match) candidate pools — the criteria
     * checked (Availability, Conflicts, Load, Full-time, Preferred
     * Block) are identical in kind, only the point weights and whether
     * "Teaching Qualification"/"College Match"/"Preferred Block" apply
     * differ between the two tiers.
     *
     * @param  \Illuminate\Support\Collection<int, Faculty>  $candidates
     */
    private function rankFacultyCandidates(
        $candidates,
        Subject $subject,
        ?SectionSubject $current,
        string $tier
    ): array {
        $isTeachingQualification = $tier === 'teaching_qualification';
        $hasSchedule = $current && $current->days && $current->start_time && $current->end_time;

        $ranked = $candidates->map(function (Faculty $faculty) use ($subject, $current, $hasSchedule, $isTeachingQualification) {
            $currentLoad = $this->currentTeachingLoad($faculty, $current);
            $loadRatio = $faculty->max_teaching_units > 0 ? $currentLoad / $faculty->max_teaching_units : 0;

            [$available, $conflictCount] = $this->facultyAvailabilityAndConflicts($faculty, $current);

            $isFullTime = $faculty->employment_type === 'Full-time';

            // Criteria "Available" only really applies once the row has
            // a Day/Time to check against — before that, treat it as
            // neutral (full points) rather than penalizing every
            // candidate for a slot that hasn't been picked yet.
            $availablePoints = (! $hasSchedule || $available) ? ($isTeachingQualification ? self::TQ_POINTS_AVAILABLE : self::COLLEGE_POINTS_AVAILABLE) : 0;

            $noConflictPoints = $conflictCount === 0 ? ($isTeachingQualification ? self::TQ_POINTS_NO_CONFLICT : self::COLLEGE_POINTS_NO_CONFLICT) : 0;

            // Lowest current load scales smoothly: a completely free
            // faculty member earns full points, one already at their
            // max_teaching_units earns none.
            $lowLoadMax = $isTeachingQualification ? self::TQ_POINTS_LOW_LOAD : self::COLLEGE_POINTS_LOW_LOAD;
            $lowLoadPoints = (int) round($lowLoadMax * (1 - min($loadRatio, 1)));

            $fullTimePoints = $isFullTime ? ($isTeachingQualification ? self::TQ_POINTS_FULL_TIME : self::COLLEGE_POINTS_FULL_TIME) : 0;

            $points = [];
            $reasons = [];

            if ($isTeachingQualification) {
                $points['teaching_qualification'] = self::TQ_POINTS_QUALIFIED;
                $reasons[] = ['label' => 'Teaching Qualification', 'met' => true];
            } else {
                $points['college_match'] = self::COLLEGE_POINTS_MATCH;
                $reasons[] = ['label' => 'College Match', 'met' => true];
            }

            $points['available'] = $availablePoints;
            $reasons[] = ['label' => 'Available', 'met' => $availablePoints > 0];

            $points['no_conflict'] = $noConflictPoints;
            $reasons[] = ['label' => 'No Schedule Conflicts', 'met' => $conflictCount === 0];

            $points['low_load'] = $lowLoadPoints;
            $reasons[] = ['label' => 'Lowest Teaching Load', 'met' => $loadRatio < 0.5];

            $points['full_time'] = $fullTimePoints;
            $reasons[] = ['label' => 'Full-time', 'met' => $isFullTime];

            $preferredBlock = false;
            if ($isTeachingQualification) {
                $preferredBlock = $this->prefersTeachingBlock($faculty, $subject);
                $points['preferred_block'] = $preferredBlock ? self::TQ_POINTS_PREFERRED_BLOCK : 0;
                $reasons[] = ['label' => 'Preferred Teaching Block', 'met' => $preferredBlock];
            }

            $score = array_sum($points);

            return [
                'id' => $faculty->id,
                'name' => $faculty->full_name,
                'faculty_category' => $faculty->faculty_category,
                'employment_type' => $faculty->employment_type,
                'is_full_time' => $isFullTime,
                'current_load' => $currentLoad,
                'max_teaching_units' => $faculty->max_teaching_units,
                'load_ratio' => $loadRatio,
                'available' => $available,
                'conflict_count' => $conflictCount,
                'tier' => $isTeachingQualification ? 'teaching_qualification' : 'college_match',
                'selected_by_college_match' => ! $isTeachingQualification,
                'score' => $score,
                'score_max' => 100,
                'score_breakdown' => $points,
                'reasons' => $reasons,
            ];
        })->values()->all();

        // RANK BY SCORE — highest Recommendation Score first. Ties are
        // broken by lowest current teaching load, then fewest
        // scheduling conflicts.
        usort($ranked, function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['current_load'] !== $b['current_load']) {
                return $a['current_load'] <=> $b['current_load'];
            }

            return $a['conflict_count'] <=> $b['conflict_count'];
        });

        $ranked = array_slice($ranked, 0, self::MAX_FACULTY_RESULTS);

        foreach ($ranked as &$item) {
            $item['confidence'] = $this->confidenceFromScore($item['score']);
        }
        unset($item);

        return $ranked;
    }

    /**
     * Single-candidate version of the scoring pass rankFacultyCandidates()
     * runs over a whole pool — same criteria (Availability, Conflicts,
     * Load, Full-time), generalized across all four tiers
     * ('teaching_qualification', 'college_match',
     * 'general_education_match', 'manual_override') so the Faculty
     * Recommendation Selector's Live Score always uses the exact same
     * math as the initial Auto Generate ranking, no matter which tier
     * the currently-selected faculty falls into.
     */
    private function scoreCandidate(Faculty $faculty, Subject $subject, ?SectionSubject $current, string $tier): array
    {
        $hasSchedule = $current && $current->days && $current->start_time && $current->end_time;

        $currentLoad = $this->currentTeachingLoad($faculty, $current);
        $loadRatio = $faculty->max_teaching_units > 0 ? $currentLoad / $faculty->max_teaching_units : 0;

        [$available, $conflictCount] = $this->facultyAvailabilityAndConflicts($faculty, $current);

        $isFullTime = $faculty->employment_type === 'Full-time';

        [$matchPoints, $availablePointsMax, $noConflictPointsMax, $lowLoadMax, $fullTimeMax, $matchLabel] = match ($tier) {
            'teaching_qualification' => [self::TQ_POINTS_QUALIFIED, self::TQ_POINTS_AVAILABLE, self::TQ_POINTS_NO_CONFLICT, self::TQ_POINTS_LOW_LOAD, self::TQ_POINTS_FULL_TIME, 'Teaching Qualification'],
            'college_match' => [self::COLLEGE_POINTS_MATCH, self::COLLEGE_POINTS_AVAILABLE, self::COLLEGE_POINTS_NO_CONFLICT, self::COLLEGE_POINTS_LOW_LOAD, self::COLLEGE_POINTS_FULL_TIME, 'College Match'],
            'general_education_match' => [self::GENED_POINTS_MATCH, self::GENED_POINTS_AVAILABLE, self::GENED_POINTS_NO_CONFLICT, self::GENED_POINTS_LOW_LOAD, self::GENED_POINTS_FULL_TIME, 'General Education Match'],
            default => [self::OVERRIDE_POINTS_MATCH, self::OVERRIDE_POINTS_AVAILABLE, self::OVERRIDE_POINTS_NO_CONFLICT, self::OVERRIDE_POINTS_LOW_LOAD, self::OVERRIDE_POINTS_FULL_TIME, 'Manual Override'],
        };

        $availablePoints = (! $hasSchedule || $available) ? $availablePointsMax : 0;
        $noConflictPoints = $conflictCount === 0 ? $noConflictPointsMax : 0;
        $lowLoadPoints = (int) round($lowLoadMax * (1 - min($loadRatio, 1)));
        $fullTimePoints = $isFullTime ? $fullTimeMax : 0;

        $points = ['match' => $matchPoints];
        $reasons = [['label' => $matchLabel, 'met' => $tier !== 'manual_override']];

        $points['available'] = $availablePoints;
        $reasons[] = ['label' => 'Available', 'met' => $availablePoints > 0];

        $points['no_conflict'] = $noConflictPoints;
        $reasons[] = ['label' => 'No Schedule Conflict', 'met' => $conflictCount === 0];

        $points['low_load'] = $lowLoadPoints;
        $reasons[] = ['label' => 'Lowest Teaching Load', 'met' => $loadRatio < 0.5];

        $points['full_time'] = $fullTimePoints;
        $reasons[] = ['label' => 'Full-time', 'met' => $isFullTime];

        if ($tier === 'teaching_qualification') {
            $preferredBlock = $this->prefersTeachingBlock($faculty, $subject);
            $points['preferred_block'] = $preferredBlock ? self::TQ_POINTS_PREFERRED_BLOCK : 0;
            $reasons[] = ['label' => 'Preferred Teaching Block', 'met' => $preferredBlock];
        }

        $score = array_sum($points);

        return [
            'id' => $faculty->id,
            'name' => $faculty->full_name,
            'faculty_category' => $faculty->faculty_category,
            'employment_type' => $faculty->employment_type,
            'is_full_time' => $isFullTime,
            'current_load' => $currentLoad,
            'max_teaching_units' => $faculty->max_teaching_units,
            'load_ratio' => $loadRatio,
            'available' => $available,
            'conflict_count' => $conflictCount,
            'tier' => $tier,
            'selected_by_college_match' => $tier === 'college_match',
            'score' => $score,
            'score_max' => 100,
            'score_breakdown' => $points,
            'reasons' => $reasons,
            'confidence' => $this->confidenceFromScore($score),
        ];
    }

    /**
     * A Faculty member's committed teaching load right now (Scheduled
     * rows, excluding the row currently being edited/generated), used
     * both for the "Lowest Teaching Load" ranking criterion and as the
     * hard teaching-load cap AutoScheduleService enforces.
     */
    private function currentTeachingLoad(Faculty $faculty, ?SectionSubject $current): int
    {
        return SectionSubject::query()
            ->where('faculty_id', $faculty->id)
            ->where('status', 'Scheduled')
            ->when($current?->id, fn ($q) => $q->where('id', '!=', $current->id))
            ->with('subject:id,units')
            ->get()
            ->sum(fn (SectionSubject $ss) => $ss->subject->units ?? 0);
    }

    /**
     * The College that owns a Subject — via Subject -> Major ->
     * Department -> College, the same chain the Curriculum for that
     * Major belongs to (a Curriculum's `major_id` always matches its
     * Subjects' `major_id`, so "the curriculum's college" and "the
     * subject's college" are one and the same lookup here). Returns
     * null for General Education subjects, which have no Major/College
     * of their own — College Matching simply doesn't apply to them.
     */
    private function subjectCollegeId(Subject $subject): ?int
    {
        $subject->loadMissing('major.department');

        return $subject->major?->department?->college_id;
    }

    /**
     * Criteria 6 — "Preferred teaching block based on subject hours".
     * True when the faculty member has at least one declared weekly
     * availability window long enough to fit the subject's total
     * contact hours (Lecture + Laboratory) in a single sitting, so the
     * subject doesn't have to be awkwardly split across days.
     */
    private function prefersTeachingBlock(Faculty $faculty, Subject $subject): bool
    {
        $totalHours = max((int) $subject->lecture_hours + (int) $subject->laboratory_hours, 1);

        $faculty->loadMissing('availabilities');

        return $faculty->availabilities->contains(function (FacultyAvailability $window) use ($totalHours) {
            if (! $window->is_available || ! $window->start_time || ! $window->end_time) {
                return false;
            }

            return $this->minutesBetween($window->start_time, $window->end_time) >= $totalHours * 60;
        });
    }

    private function minutesBetween(string $start, string $end): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', substr($start, 0, 5)));
        [$endHour, $endMinute] = array_map('intval', explode(':', substr($end, 0, 5)));

        return ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute);
    }

    /**
     * Shared Recommendation Score -> Badge mapping used for Faculty,
     * Room, and Time candidates alike, so "Best Match" / "Good Match"
     * / "Alternative" always means the same score range everywhere in
     * the UI.
     */
    private function confidenceFromScore(int $score): string
    {
        if ($score >= 85) {
            return 'Best Match';
        }

        if ($score >= 65) {
            return 'Good Match';
        }

        return 'Alternative';
    }

    /**
     * INTELLIGENT ROOM ASSIGNMENT — hierarchical strategy.
     *
     * A room only ever appears in the returned list if it clears three
     * hard requirements, evaluated in this order:
     *
     *   Priority 1 — Room Type MUST match the Subject (Lecture subjects
     *                only get Lecture rooms, Laboratory subjects only
     *                get Laboratory rooms). No match, no candidacy.
     *   Priority 5 — Capacity MUST be >= the estimated headcount.
     *   Priority 6 — Availability: the room must have no scheduling
     *                conflict at the row's current Day/Time (skipped
     *                — treated as neutral — when the row has no
     *                Day/Time yet, same as before).
     *
     * Rooms that fail any of the three are excluded outright — never
     * scored down, never shown ("do not recommend that room").
     *
     * Among the rooms that clear all three, a room is only a valid
     * candidate if it is scoped to the Section's own Program, the
     * Section's own College, or is a fully Shared room (no College/
     * Department at all). A room scoped to a *different* College is
     * excluded too — it was never meant for this Section. Candidates
     * are then tiered (Priorities 2–4):
     *
     *   Program tier (exact Department match) ......... base + 16 pts
     *   College tier  (same College, any Program) ...... base + 8 pts
     *   Shared tier   (no College/Department at all) ... base + 0 pts
     *
     * where base = Room Type (34) + Capacity (30) + Availability (20)
     * = 84, always earned in full since those three are hard filters
     * — every returned room already satisfies them. Score is therefore
     * 84 (Shared) / 92 (College) / 100 (Program).
     *
     * Kept modular: ROOM_POINTS_* constants and resolveRoomScopeTier()
     * are the only places that would need to change to add future
     * criteria (equipment, building proximity, air conditioning,
     * etc.) without touching the hard-filter or ranking logic.
     */
    private const ROOM_POINTS_TYPE_MATCH = 34;

    private const ROOM_POINTS_CAPACITY_OK = 30;

    private const ROOM_POINTS_AVAILABLE = 20;

    private const ROOM_POINTS_SCOPE_PROGRAM = 16;

    private const ROOM_POINTS_SCOPE_COLLEGE = 8;

    private const ROOM_POINTS_SCOPE_SHARED = 0;

    public function recommendRooms(Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        $section->loadMissing('major.department');

        $wantsLaboratory = (int) $subject->laboratory_hours > 0;
        $preferredType = $wantsLaboratory ? 'Laboratory' : 'Lecture';

        $sectionDepartmentId = $section->major?->department_id;
        $sectionCollegeId = $section->major?->department?->college_id;

        $capacityNeeded = $current?->capacity ?? $section->estimated_students ?? 0;

        $allRooms = Room::query()->where('status', 'Active')->with(['department', 'college'])->get();

        if ($allRooms->isEmpty()) {
            return ['recommendations' => [], 'message' => 'No active rooms found.', 'reasons' => ['No active rooms are configured in the Room Master.']];
        }

        // --- Hard filters (Priorities 1, 5, 6) -----------------------
        // A room that fails any of these is never a candidate, no
        // matter how well it scopes to the Section — it simply isn't
        // usable for this Subject at this time.
        $typeExcluded = 0;
        $capacityExcluded = 0;
        $conflictExcluded = 0;
        $scopeExcluded = 0;

        $eligible = $allRooms->filter(function (Room $room) use (
            $preferredType, $capacityNeeded, $current, $sectionDepartmentId, $sectionCollegeId,
            &$typeExcluded, &$capacityExcluded, &$conflictExcluded, &$scopeExcluded,
        ) {
            if ($room->room_type !== $preferredType) {
                $typeExcluded++;

                return false;
            }

            if ($capacityNeeded && $room->capacity < $capacityNeeded) {
                $capacityExcluded++;

                return false;
            }

            if ($this->roomConflictCount($room, $current) > 0) {
                $conflictExcluded++;

                return false;
            }

            if ($this->resolveRoomScopeTier($room, $sectionDepartmentId, $sectionCollegeId) === null) {
                // Scoped to a different College/Program entirely —
                // never a valid candidate for this Section.
                $scopeExcluded++;

                return false;
            }

            return true;
        })->values();

        if ($eligible->isEmpty()) {
            return [
                'recommendations' => [],
                'message' => 'No suitable room available.',
                'reasons' => $this->noRoomReasons($preferredType, $typeExcluded, $capacityExcluded, $conflictExcluded, $scopeExcluded),
            ];
        }

        // Used for the Closest Capacity Match tie-breaker among rooms
        // that already cleared the hard capacity filter — the room
        // whose capacity is nearest to what's needed sorts first
        // among equally-scored candidates, so a needlessly huge room
        // never outranks a right-sized one.
        $closestCapacity = $eligible->pluck('capacity')->min();

        $ranked = $eligible->map(function (Room $room) use (
            $sectionDepartmentId, $sectionCollegeId, $closestCapacity, $current,
        ) {
            $tier = $this->resolveRoomScopeTier($room, $sectionDepartmentId, $sectionCollegeId);

            $scopePoints = match ($tier) {
                'program' => self::ROOM_POINTS_SCOPE_PROGRAM,
                'college' => self::ROOM_POINTS_SCOPE_COLLEGE,
                default => self::ROOM_POINTS_SCOPE_SHARED,
            };

            $points = [
                'room_type_match' => self::ROOM_POINTS_TYPE_MATCH,
                'capacity_ok' => self::ROOM_POINTS_CAPACITY_OK,
                'available' => self::ROOM_POINTS_AVAILABLE,
                'scope' => $scopePoints,
            ];

            $score = array_sum($points);

            $reasons = [['label' => 'Correct Room Type', 'met' => true, 'type' => 'success']];

            if ($tier === 'program') {
                $reasons[] = ['label' => 'Same Program', 'met' => true, 'type' => 'success'];
                $reasons[] = ['label' => 'Same College', 'met' => true, 'type' => 'success'];
            } elseif ($tier === 'college') {
                $reasons[] = ['label' => 'Same College', 'met' => true, 'type' => 'success'];
                $reasons[] = ['label' => 'Shared by all programs', 'met' => true, 'type' => 'warning'];
            } else {
                $reasons[] = ['label' => 'Shared Room', 'met' => true, 'type' => 'success'];
            }

            $reasons[] = ['label' => 'Capacity OK', 'met' => true, 'type' => 'success'];
            $reasons[] = ['label' => 'Available', 'met' => true, 'type' => 'success'];

            return [
                'id' => $room->id,
                'name' => "{$room->room_code} — {$room->room_name}",
                'room_type' => $room->room_type,
                'room_category' => $room->room_category,
                'department' => $room->department?->name,
                'college' => $room->college?->name,
                'capacity' => $room->capacity,
                'match_tier' => $tier,
                'badge' => match ($tier) {
                    'program' => 'Program Match',
                    'college' => 'College Match',
                    default => 'Shared Room',
                },
                'score' => $score,
                'score_max' => 100,
                'score_breakdown' => $points,
                'reasons' => $reasons,
            ];
        })->values()->all();

        usort($ranked, function (array $a, array $b) use ($closestCapacity) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            // Tie-break: capacity closest to what's needed wins over a
            // needlessly larger room.
            return abs($a['capacity'] - $closestCapacity) <=> abs($b['capacity'] - $closestCapacity);
        });

        $ranked = array_slice($ranked, 0, self::MAX_ROOM_RESULTS);

        foreach ($ranked as &$item) {
            $item['confidence'] = $this->confidenceFromScore($item['score']);
        }
        unset($item);

        return ['recommendations' => $ranked, 'message' => null, 'reasons' => []];
    }

    /**
     * ROOM RECOMMENDATION SELECTOR.
     *
     * Powers the interactive Room selector on the Auto Generate review
     * panel, mirroring facultyOptionsForSelector(). Returns the same
     * ranked, hard-filtered recommendation list recommendRooms()
     * produces (so "recommended first, AI pick defaulted" always
     * matches what Auto Generate itself chose), plus — when the
     * Registrar types into the search box — a global search across
     * every Active room regardless of Type/College/Capacity/
     * Availability, each scored via scoreArbitraryRoom() so the
     * dropdown can show a live Recommendation Score (and a clear
     * "why this isn't ideal" explanation) even for a room the hard
     * filters would normally exclude — the Registrar always keeps
     * full manual override freedom.
     */
    public function roomOptionsForSelector(Subject $subject, Section $section, ?SectionSubject $current, ?string $search = null): array
    {
        $recommended = $this->recommendRooms($subject, $section, $current);

        $searchResults = [];

        if ($search !== null && trim($search) !== '') {
            $recommendedIds = collect($recommended['recommendations'])->pluck('id')->all();

            $matches = Room::query()
                ->where('status', 'Active')
                ->where(function ($q) use ($search) {
                    $q->where('room_code', 'like', "%{$search}%")
                        ->orWhere('room_name', 'like', "%{$search}%");
                })
                ->whereNotIn('id', $recommendedIds)
                ->with(['department', 'college'])
                ->limit(20)
                ->get();

            $searchResults = $matches
                ->map(fn (Room $room) => $this->scoreArbitraryRoom($room, $subject, $section, $current))
                ->sortByDesc('score')
                ->values()
                ->all();
        }

        return [
            'recommended' => $recommended['recommendations'],
            'message' => $recommended['message'],
            'search_results' => $searchResults,
        ];
    }

    /**
     * Score ANY single Active room against a Subject/Section — used
     * both for global search results and for recomputing the Live
     * Score the instant the Registrar manually overrides the
     * AI-selected room with one outside the recommended pool (e.g.
     * searching "Room 108" directly by code).
     *
     * Unlike recommendRooms()'s hard filters, nothing here excludes a
     * room — every check that would normally disqualify it (Wrong
     * Room Type, Capacity Too Small, Occupied, Different College) is
     * instead surfaced as a failed (✗) reason and an explanatory
     * override_reason, exactly like scoreArbitraryFaculty() does for
     * Manual Override faculty picks. The Registrar always keeps full
     * freedom to pick it anyway.
     */
    public function scoreArbitraryRoom(Room $room, Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        $section->loadMissing('major.department');
        $room->loadMissing(['department', 'college']);

        $wantsLaboratory = (int) $subject->laboratory_hours > 0;
        $preferredType = $wantsLaboratory ? 'Laboratory' : 'Lecture';
        $typeMatch = $room->room_type === $preferredType;

        $capacityNeeded = $current?->capacity ?? $section->estimated_students ?? 0;
        $capacityOk = $capacityNeeded ? $room->capacity >= $capacityNeeded : true;

        $conflictCount = $this->roomConflictCount($room, $current);
        $available = $conflictCount === 0;

        $sectionDepartmentId = $section->major?->department_id;
        $sectionCollegeId = $section->major?->department?->college_id;
        $tier = $this->resolveRoomScopeTier($room, $sectionDepartmentId, $sectionCollegeId) ?? 'mismatch';

        $points = [
            'room_type_match' => $typeMatch ? self::ROOM_POINTS_TYPE_MATCH : 0,
            'capacity_ok' => $capacityOk ? self::ROOM_POINTS_CAPACITY_OK : 0,
            'available' => $available ? self::ROOM_POINTS_AVAILABLE : 0,
            'scope' => match ($tier) {
                'program' => self::ROOM_POINTS_SCOPE_PROGRAM,
                'college' => self::ROOM_POINTS_SCOPE_COLLEGE,
                'shared' => self::ROOM_POINTS_SCOPE_SHARED,
                default => 0,
            },
        ];

        $score = array_sum($points);

        $reasons = [
            ['label' => $typeMatch ? 'Correct Room Type' : 'Wrong Room Type', 'met' => $typeMatch, 'type' => $typeMatch ? 'success' : 'danger'],
        ];

        if ($tier === 'program') {
            $reasons[] = ['label' => 'Same Program', 'met' => true, 'type' => 'success'];
            $reasons[] = ['label' => 'Same College', 'met' => true, 'type' => 'success'];
        } elseif ($tier === 'college') {
            $reasons[] = ['label' => 'Same College', 'met' => true, 'type' => 'success'];
            $reasons[] = ['label' => 'Shared by all programs', 'met' => true, 'type' => 'warning'];
        } elseif ($tier === 'shared') {
            $reasons[] = ['label' => 'Shared Room', 'met' => true, 'type' => 'success'];
        } else {
            $reasons[] = ['label' => 'Different College', 'met' => false, 'type' => 'danger'];
        }

        $reasons[] = ['label' => $capacityOk ? 'Capacity OK' : 'Capacity Too Small', 'met' => $capacityOk, 'type' => $capacityOk ? 'success' : 'danger'];
        $reasons[] = ['label' => $available ? 'Available' : 'Occupied at this time', 'met' => $available, 'type' => $available ? 'success' : 'danger'];

        $isManualOverride = ! $typeMatch || ! $capacityOk || ! $available || $tier === 'mismatch';

        $badge = match (true) {
            $isManualOverride => 'Manual Override',
            $tier === 'program' => 'Program Match',
            $tier === 'college' => 'College Match',
            default => 'Shared Room',
        };

        $overrideReason = null;
        if ($isManualOverride) {
            $issues = [];
            if (! $typeMatch) {
                $issues[] = "requires a {$preferredType} room";
            }
            if (! $capacityOk) {
                $issues[] = "needs seating for at least {$capacityNeeded} students";
            }
            if (! $available) {
                $issues[] = 'is already booked at this day/time';
            }
            if ($tier === 'mismatch') {
                $issues[] = "belongs to a different College than this Section";
            }
            $overrideReason = 'This room '.implode(', ', $issues).'.';
        }

        return [
            'id' => $room->id,
            'name' => "{$room->room_code} — {$room->room_name}",
            'room_type' => $room->room_type,
            'room_category' => $room->room_category,
            'department' => $room->department?->name,
            'college' => $room->college?->name,
            'capacity' => $room->capacity,
            'match_tier' => $tier,
            'score' => $score,
            'score_max' => 100,
            'score_breakdown' => $points,
            'reasons' => $reasons,
            'confidence' => $this->confidenceFromScore($score),
            'badge' => $badge,
            'manual_override' => $isManualOverride,
            'override_reason' => $overrideReason,
        ];
    }

    /**
     * Resolve which scoping tier a Room falls into for a given
     * Section, or null if the Room belongs to a College/Department
     * that ISN'T the Section's own — i.e. not a valid candidate at
     * all (Priorities 2–4 of the Intelligent Room Assignment spec).
     *
     *   'program' — Room's Department is exactly the Section's own.
     *   'college'  — Room has no Department (or a different one) but
     *                its College matches the Section's College.
     *   'shared'   — Room has no College and no Department — usable
     *                by any Section.
     *   null       — Room is scoped to a College the Section doesn't
     *                belong to (or a Department without a College
     *                match) — never recommended.
     */
    private function resolveRoomScopeTier(Room $room, ?int $sectionDepartmentId, ?int $sectionCollegeId): ?string
    {
        if (! $room->college_id && ! $room->department_id) {
            return 'shared';
        }

        if ($sectionDepartmentId && $room->department_id === $sectionDepartmentId) {
            return 'program';
        }

        if ($sectionCollegeId && $room->college_id === $sectionCollegeId) {
            return 'college';
        }

        return null;
    }

    /**
     * Build the itemized "No suitable room available" reasons the
     * spec calls for, based on exactly which hard filter(s) emptied
     * the candidate pool.
     *
     * @return list<string>
     */
    private function noRoomReasons(
        string $preferredType,
        int $typeExcluded,
        int $capacityExcluded,
        int $conflictExcluded,
        int $scopeExcluded,
    ): array {
        $reasons = [];

        if ($typeExcluded > 0 && $capacityExcluded === 0 && $conflictExcluded === 0 && $scopeExcluded === 0) {
            $reasons[] = "No available {$preferredType} room found.";
        }

        if ($capacityExcluded > 0) {
            $reasons[] = 'The available rooms are too small for the estimated number of students.';
        }

        if ($conflictExcluded > 0) {
            $reasons[] = 'All matching rooms are occupied during this time.';
        }

        if ($scopeExcluded > 0 && $typeExcluded === 0) {
            $reasons[] = 'No room belonging to this Program, College, or a Shared room, is currently free.';
        }

        if (empty($reasons)) {
            $reasons[] = "No {$preferredType} room is currently available for this Section.";
        }

        return $reasons;
    }

    /**
     * TIME RECOMMENDATION.
     *
     * Recommends available Time periods (Day pattern + Start/End
     * Time) that do NOT conflict with the given candidate Faculty,
     * Room, or the Section itself — reusing ScheduleConflictService's
     * exact overlap rule so this can never disagree with what happens
     * when the Registrar actually tries to save. Returns the first
     * available options, best (earliest, most standard) first.
     */
    public function recommendTimes(
        Subject $subject,
        Section $section,
        ?int $facultyId,
        ?int $roomId,
        ?SectionSubject $current = null
    ): array {
        $totalHours = (int) $subject->lecture_hours + (int) $subject->laboratory_hours;
        if ($totalHours <= 0) {
            $totalHours = 3;
        }

        $excludingId = $current?->id ?? 0;
        $results = [];
        $slotIndex = 0;

        foreach (self::DAY_PATTERNS as $days) {
            $sessionMinutes = (int) round(($totalHours / count($days)) * 60);

            foreach (self::START_TIMES as $start) {
                [$hour, $minute] = array_map('intval', explode(':', $start));
                $endMinutes = ($hour * 60 + $minute) + $sessionMinutes;

                // Don't recommend a session that runs past a normal
                // 8:00 PM academic day.
                if ($endMinutes > 20 * 60) {
                    continue;
                }

                $end = sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60);

                $hasConflict = false;

                if ($section->id) {
                    $hasConflict = $hasConflict || (bool) $this->conflictService->findSectionConflict(
                        $section->id, $excludingId, $days, $start, $end
                    );
                }
                if (! $hasConflict && $facultyId) {
                    $hasConflict = (bool) $this->conflictService->findFacultyConflict(
                        $facultyId, $excludingId, $days, $start, $end
                    );
                }
                if (! $hasConflict && $roomId) {
                    $hasConflict = (bool) $this->conflictService->findRoomConflict(
                        $roomId, $excludingId, $days, $start, $end
                    );
                }

                if (! $hasConflict) {
                    // TIME RECOMMENDATION SCORE (100 points) — the four
                    // checks the spec calls out (Faculty Availability,
                    // Room Availability, Section Availability, Fits
                    // Subject Hours) are all satisfied by construction
                    // once a slot survives the conflict checks above,
                    // so each is awarded its fixed share of the score.
                    // The remaining share is a preference bonus that
                    // favors earlier, more standard slots (fewer
                    // day-splits, earlier start) — the same "best
                    // available time blocks first" ordering the spec
                    // asks for.
                    $preferenceBonus = max(50 - ($slotIndex * 8), 0);

                    $points = [
                        'faculty_available' => 15,
                        'room_available' => 15,
                        'section_available' => 10,
                        'fits_subject_hours' => 10,
                        'preferred_slot' => $preferenceBonus,
                    ];

                    $results[] = [
                        'days' => $days,
                        'start_time' => $start,
                        'end_time' => $end,
                        'score' => array_sum($points),
                        'score_max' => 100,
                        'score_breakdown' => $points,
                        'reasons' => [
                            ['label' => 'Faculty Available', 'met' => true],
                            ['label' => 'Room Available', 'met' => true],
                            ['label' => 'Section Available', 'met' => true],
                            ['label' => 'Fits Subject Hours', 'met' => true],
                        ],
                    ];

                    $slotIndex++;
                }

                if (count($results) >= self::MAX_TIME_RESULTS) {
                    break 2;
                }
            }
        }

        if (empty($results)) {
            return ['recommendations' => [], 'message' => 'No available time slot found without conflicts.'];
        }

        foreach ($results as &$item) {
            $item['confidence'] = $this->confidenceFromScore($item['score']);
        }
        unset($item);

        return ['recommendations' => $results, 'message' => null];
    }

    /**
     * Whether a Faculty member is free (per their declared weekly
     * FacultyAvailability windows) during the row's currently
     * selected Days/Start/End Time, plus how many existing schedule
     * conflicts they'd have at that same slot. Ranking factors 4 and
     * 5 only make sense once a Day/Time is actually on the row — if
     * the row has no Day/Time yet, both are treated as neutral
     * (available = true, conflicts = 0) so ranking falls back to
     * Department + Load alone.
     *
     * @return array{0: bool, 1: int}
     */
    private function facultyAvailabilityAndConflicts(Faculty $faculty, ?SectionSubject $current): array
    {
        if (! $current || ! $current->days || ! $current->start_time || ! $current->end_time) {
            return [true, 0];
        }

        $dayTokens = array_filter(explode(',', $current->days));

        $faculty->loadMissing('availabilities');

        $available = true;
        foreach ($dayTokens as $token) {
            $dayOfWeek = self::DAY_MAP[$token] ?? $token;
            $window = $faculty->availabilities->firstWhere('day_of_week', $dayOfWeek);

            if (! $window || ! $window->is_available
                || $current->start_time < $window->start_time
                || $current->end_time > $window->end_time
            ) {
                $available = false;
                break;
            }
        }

        $conflict = $this->conflictService->findFacultyConflict(
            $faculty->id, $current->id, $dayTokens, $current->start_time, $current->end_time
        );

        return [$available, $conflict ? 1 : 0];
    }

    /**
     * How many scheduling conflicts a candidate Room would have at
     * the row's currently selected Day/Time. Zero (neutral) if the
     * row doesn't have a Day/Time selected yet.
     */
    private function roomConflictCount(Room $room, ?SectionSubject $current): int
    {
        if (! $current || ! $current->days || ! $current->start_time || ! $current->end_time) {
            return 0;
        }

        $dayTokens = array_filter(explode(',', $current->days));

        $conflict = $this->conflictService->findRoomConflict(
            $room->id, $current->id, $dayTokens, $current->start_time, $current->end_time
        );

        return $conflict ? 1 : 0;
    }

}