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

        return compact('faculty', 'room', 'time');
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
     * ROOM RECOMMENDATION SCORE (100 points):
     *
     *   1. Correct room type for the subject ............ 40 pts
     *   2. Enough capacity for the section ............... 30 pts
     *   3. Currently available (no conflict) ............. 20 pts
     *   4. Fewest conflicts (tie-breaker bonus) ........... 10 pts
     *
     * Only Active rooms are considered. Ranked by score, then by the
     * room whose capacity is closest to (but never under) what's
     * needed, so a 300-seat hall never outranks a right-sized room.
     */
    private const ROOM_POINTS_TYPE_MATCH = 40;

    private const ROOM_POINTS_CAPACITY_OK = 30;

    private const ROOM_POINTS_AVAILABLE = 20;

    private const ROOM_POINTS_FEWEST_CONFLICTS = 10;

    public function recommendRooms(Subject $subject, Section $section, ?SectionSubject $current = null): array
    {
        $wantsLaboratory = (int) $subject->laboratory_hours > 0;
        $preferredType = $wantsLaboratory ? 'Laboratory' : 'Lecture';

        $capacityNeeded = $current?->capacity ?? $section->estimated_students ?? 0;

        $rooms = Room::query()->where('status', 'Active')->get();

        if ($rooms->isEmpty()) {
            return ['recommendations' => [], 'message' => 'No active rooms found.'];
        }

        $ranked = $rooms->map(function (Room $room) use ($preferredType, $capacityNeeded, $current) {
            $typeMatch = $room->room_type === $preferredType;
            $capacityOk = $capacityNeeded ? $room->capacity >= $capacityNeeded : true;
            $conflictCount = $this->roomConflictCount($room, $current);
            $available = $conflictCount === 0;

            $points = [
                'type_match' => $typeMatch ? self::ROOM_POINTS_TYPE_MATCH : 0,
                'capacity_ok' => $capacityOk ? self::ROOM_POINTS_CAPACITY_OK : 0,
                'available' => $available ? self::ROOM_POINTS_AVAILABLE : 0,
                'fewest_conflicts' => $conflictCount === 0 ? self::ROOM_POINTS_FEWEST_CONFLICTS : 0,
            ];

            $score = array_sum($points);

            $reasons = [
                ['label' => 'Correct Room Type', 'met' => $typeMatch],
                ['label' => 'Enough Capacity', 'met' => $capacityOk],
                ['label' => 'Currently Available', 'met' => $available],
                ['label' => 'Fewest Conflicts', 'met' => $conflictCount === 0],
            ];

            return [
                'id' => $room->id,
                'name' => "{$room->room_code} — {$room->room_name}",
                'room_type' => $room->room_type,
                'capacity' => $room->capacity,
                'type_match' => $typeMatch,
                'capacity_ok' => $capacityOk,
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