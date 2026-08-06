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
     * FACULTY RECOMMENDATION SCORE (100 points, Major subjects):
     *
     *   1. Qualified to teach the subject ............. 40 pts
     *   2. Same Department as the Section .............. 20 pts
     *   3. Same College as the Section ................. 10 pts
     *   4. Available during the selected Day/Time ...... 15 pts
     *   5. Lowest current teaching load ................ 10 pts
     *   6. Preferred teaching block for subject hours ... 5 pts
     *
     * Faculty who are not qualified are excluded entirely — this is a
     * hard filter, never a scoring penalty.
     *
     * FOR GENERAL EDUCATION SUBJECTS: Department matching is ignored
     * entirely (criteria 2 and 3 never apply). Only General Education
     * Faculty who are qualified to teach the subject are considered,
     * ranked by Qualified -> Availability -> Lowest Teaching Load.
     */
    private const FACULTY_POINTS_QUALIFIED = 40;

    private const FACULTY_POINTS_SAME_DEPARTMENT = 20;

    private const FACULTY_POINTS_SAME_COLLEGE = 10;

    private const FACULTY_POINTS_AVAILABLE = 15;

    private const FACULTY_POINTS_LOW_LOAD = 10;

    private const FACULTY_POINTS_PREFERRED_BLOCK = 5;

    public function recommendFaculty(Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        $section->loadMissing('major.department');
        $major = $section->major;
        $departmentId = $major?->department_id;
        $collegeId = $major?->department?->college_id;

        $isGeneralEducation = $subject->category === 'General Education';

        // HARD FILTER — only faculty qualified (via the faculty_subject
        // pivot) to teach this exact subject are ever considered. For
        // General Education subjects, only General Education Faculty
        // are eligible; for Major subjects, only Department Faculty.
        $qualified = Faculty::query()
            ->where('status', 'Active')
            ->where('faculty_category', $isGeneralEducation ? 'General Education Faculty' : 'Department Faculty')
            ->with(['subjects:id', 'department:id,college_id', 'availabilities'])
            ->get()
            ->filter(fn (Faculty $faculty) => $faculty->subjects->pluck('id')->contains($subject->id))
            ->values();

        if ($qualified->isEmpty()) {
            return ['recommendations' => [], 'message' => 'No qualified faculty found.'];
        }

        $hasSchedule = $current && $current->days && $current->start_time && $current->end_time;

        $ranked = $qualified->map(function (Faculty $faculty) use (
            $departmentId, $collegeId, $isGeneralEducation, $subject, $current, $hasSchedule
        ) {
            $currentLoad = SectionSubject::query()
                ->where('faculty_id', $faculty->id)
                ->where('status', 'Scheduled')
                ->when($current?->id, fn ($q) => $q->where('id', '!=', $current->id))
                ->with('subject:id,units')
                ->get()
                ->sum(fn (SectionSubject $ss) => $ss->subject->units ?? 0);

            $loadRatio = $faculty->max_teaching_units > 0 ? $currentLoad / $faculty->max_teaching_units : 0;

            [$available, $conflictCount] = $this->facultyAvailabilityAndConflicts($faculty, $current);

            $sameDepartment = ! $isGeneralEducation && $faculty->department_id === $departmentId;
            // Being in the same Department implies being in the same
            // College, so this is never false when sameDepartment is true.
            $sameCollege = ! $isGeneralEducation && ($sameDepartment || $faculty->department?->college_id === $collegeId);

            $preferredBlock = $this->prefersTeachingBlock($faculty, $subject);

            // Criteria 4 (Available) only really applies once the row
            // has a Day/Time to check against — before that, treat it
            // as neutral (full points) rather than penalizing every
            // candidate for a slot that hasn't been picked yet.
            $availablePoints = (! $hasSchedule || $available) ? self::FACULTY_POINTS_AVAILABLE : 0;

            // Criteria 5 (Lowest current load) scales smoothly: a
            // completely free faculty member earns full points, one
            // already at their max_teaching_units earns none.
            $lowLoadPoints = (int) round(self::FACULTY_POINTS_LOW_LOAD * (1 - min($loadRatio, 1)));

            $points = [
                'qualified' => self::FACULTY_POINTS_QUALIFIED,
                'same_department' => $sameDepartment ? self::FACULTY_POINTS_SAME_DEPARTMENT : 0,
                'same_college' => $sameCollege ? self::FACULTY_POINTS_SAME_COLLEGE : 0,
                'available' => $availablePoints,
                'low_load' => $lowLoadPoints,
                'preferred_block' => $preferredBlock ? self::FACULTY_POINTS_PREFERRED_BLOCK : 0,
            ];

            $score = array_sum($points);

            $reasons = [['label' => 'Qualified', 'met' => true]];
            if (! $isGeneralEducation) {
                $reasons[] = ['label' => 'Same Department', 'met' => $sameDepartment];
                $reasons[] = ['label' => 'Same College', 'met' => $sameCollege];
            }
            $reasons[] = ['label' => 'Available', 'met' => $availablePoints > 0];
            $reasons[] = ['label' => 'Low Teaching Load', 'met' => $loadRatio < 0.5];
            $reasons[] = ['label' => 'Preferred Teaching Block', 'met' => $preferredBlock];

            return [
                'id' => $faculty->id,
                'name' => $faculty->full_name,
                'faculty_category' => $faculty->faculty_category,
                'same_department' => $sameDepartment,
                'same_college' => $sameCollege,
                'current_load' => $currentLoad,
                'max_teaching_units' => $faculty->max_teaching_units,
                'load_ratio' => $loadRatio,
                'available' => $available,
                'conflict_count' => $conflictCount,
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

        return ['recommendations' => $ranked, 'message' => null];
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
     * ROOM RECOMMENDATION SCORE (100 points) — Prompt 8.8.
     *
     *   1. Preferred Room Category matches the Subject ... 40 pts
     *      e.g. "Computer Programming" -> "Computer Laboratory",
     *      "PE" -> "Gymnasium". Falls back to the coarse
     *      Lecture/Laboratory room_type match when either the
     *      Subject or the Room hasn't been given a fine-grained
     *      category yet, so older data doesn't just score zero.
     *   2. Room capacity is sufficient for the Section ...... 25 pts
     *   3. Room is currently available (no conflict) ........ 20 pts
     *   4. Same College or Department as the Section ........ 10 pts
     *   5. Closest capacity match (avoids oversized rooms) ... 5 pts
     *
     * Only Active rooms are considered. Never assigns anything —
     * this only ranks and explains; the Registrar always clicks
     * Apply (or doesn't) themselves.
     */
    private const ROOM_POINTS_CATEGORY_MATCH = 40;

    private const ROOM_POINTS_CAPACITY_OK = 25;

    private const ROOM_POINTS_AVAILABLE = 20;

    private const ROOM_POINTS_SAME_DEPARTMENT_OR_COLLEGE = 10;

    private const ROOM_POINTS_CLOSEST_CAPACITY = 5;

    public function recommendRooms(Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        $section->loadMissing('major.department');

        $wantsLaboratory = (int) $subject->laboratory_hours > 0;
        $preferredType = $wantsLaboratory ? 'Laboratory' : 'Lecture';
        $preferredCategory = $subject->preferred_room_category;

        $sectionDepartmentId = $section->major?->department_id;
        $sectionCollegeId = $section->major?->department?->college_id;

        $capacityNeeded = $current?->capacity ?? $section->estimated_students ?? 0;

        $rooms = Room::query()->where('status', 'Active')->with(['department', 'college'])->get();

        if ($rooms->isEmpty()) {
            return ['recommendations' => [], 'message' => 'No active rooms found.'];
        }

        // Used for the Closest Capacity Match tie-breaker — the room
        // whose capacity is nearest to (but never under) what's
        // needed gets the full 5 points; every other qualifying room
        // is scaled down from there so a needlessly huge room never
        // outranks a right-sized one.
        $eligibleCapacities = $rooms
            ->filter(fn (Room $room) => ! $capacityNeeded || $room->capacity >= $capacityNeeded)
            ->pluck('capacity');
        $closestCapacity = $eligibleCapacities->isNotEmpty() ? $eligibleCapacities->min() : null;

        $ranked = $rooms->map(function (Room $room) use (
            $preferredType,
            $preferredCategory,
            $sectionDepartmentId,
            $sectionCollegeId,
            $capacityNeeded,
            $closestCapacity,
            $current,
        ) {
            // 1. Category match — prefer the fine-grained category;
            // fall back to the coarse Lecture/Laboratory room_type
            // when either side hasn't set a category yet.
            if ($preferredCategory && $room->room_category) {
                $categoryMatch = $room->room_category === $preferredCategory;
            } else {
                $categoryMatch = $room->room_type === $preferredType;
            }

            // 2. Capacity sufficient
            $capacityOk = $capacityNeeded ? $room->capacity >= $capacityNeeded : true;

            // 3. Availability (no conflict)
            $conflictCount = $this->roomConflictCount($room, $current);
            $available = $conflictCount === 0;

            // 4. Same College or Department as the Section
            $sameDepartment = $sectionDepartmentId && $room->department_id
                && $room->department_id === $sectionDepartmentId;
            $sameCollege = $sectionCollegeId && $room->college_id
                && $room->college_id === $sectionCollegeId;
            $sameDepartmentOrCollege = $sameDepartment || $sameCollege;

            // 5. Closest capacity match — only meaningful among rooms
            // that already satisfy criterion 2.
            $closestCapacityPoints = 0;
            if ($capacityOk && $closestCapacity !== null && $room->capacity > 0) {
                // Full points at the closest capacity, linearly
                // tapering to 0 as the room gets proportionally larger
                // than necessary — an 800-seat hall for a 30-seat
                // class scores near 0 here even though it "fits".
                $ratio = $closestCapacity / $room->capacity;
                $closestCapacityPoints = (int) round(self::ROOM_POINTS_CLOSEST_CAPACITY * $ratio);
            }

            $points = [
                'category_match' => $categoryMatch ? self::ROOM_POINTS_CATEGORY_MATCH : 0,
                'capacity_ok' => $capacityOk ? self::ROOM_POINTS_CAPACITY_OK : 0,
                'available' => $available ? self::ROOM_POINTS_AVAILABLE : 0,
                'same_department_or_college' => $sameDepartmentOrCollege ? self::ROOM_POINTS_SAME_DEPARTMENT_OR_COLLEGE : 0,
                'closest_capacity' => $closestCapacityPoints,
            ];

            $score = array_sum($points);

            $reasons = [
                ['label' => $categoryMatch ? 'Correct Room Type' : 'Wrong Room Type', 'met' => $categoryMatch],
                ['label' => 'Enough Capacity', 'met' => $capacityOk],
                ['label' => 'Available', 'met' => $available],
                ['label' => 'Same Department', 'met' => $sameDepartmentOrCollege],
            ];

            return [
                'id' => $room->id,
                'name' => "{$room->room_code} — {$room->room_name}",
                'room_type' => $room->room_type,
                'room_category' => $room->room_category,
                'department' => $room->department?->name,
                'college' => $room->college?->name,
                'capacity' => $room->capacity,
                'category_match' => $categoryMatch,
                'capacity_ok' => $capacityOk,
                'same_department_or_college' => $sameDepartmentOrCollege,
                'conflict_count' => $conflictCount,
                'score' => $score,
                'score_max' => 100,
                'score_breakdown' => $points,
                'reasons' => $reasons,
            ];
        })->values()->all();

        usort($ranked, function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            // Prefer the room whose capacity is closest to (but not
            // under) what's needed, over one that's needlessly huge.
            return $a['capacity'] <=> $b['capacity'];
        });

        $ranked = array_slice($ranked, 0, self::MAX_ROOM_RESULTS);

        foreach ($ranked as &$item) {
            $item['confidence'] = $this->confidenceFromScore($item['score']);
        }
        unset($item);

        return ['recommendations' => $ranked, 'message' => null];
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