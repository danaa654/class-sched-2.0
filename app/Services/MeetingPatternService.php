<?php

namespace App\Services;

use App\Models\Subject;

/**
 * MEETING PATTERN INTELLIGENCE.
 *
 * Decides HOW MANY times a week a Subject should meet, and WHICH Day
 * combinations are acceptable, before RecommendationService::recommendTimes()
 * ever starts hunting for an open Start/End Time.
 *
 * This replaces the old hardcoded "always try Mon-Wed-Fri, then
 * Tue-Thu, then Mon-Wed..." list that used to live directly on
 * RecommendationService. That list mixed 3-meeting and 2-meeting
 * patterns together with no regard for what kind of Subject it was,
 * which is why the Auto Generate engine kept producing MWF lectures.
 *
 * Rules (per the Registrar's spec):
 *   - Lecture subjects        -> 2 meetings/week (Mon&Wed or Tue&Thu preferred)
 *   - Laboratory subjects     -> 1 meeting/week, one continuous block
 *   - Special subjects        -> 1 meeting/week (NSTP, PE, OJT, Thesis,
 *                                 Capstone, Practicum, Seminar, ...)
 *   - 3-meetings/week (MWF, TThS, ...) is NEVER generated unless a
 *     future administrator-facing config explicitly raises the cap.
 *
 * Everything here is config-driven (config/scheduling.php) so a future
 * "3 meetings/week" toggle, or per-college/per-subject overrides, can
 * be added without touching RecommendationService or AutoScheduleService
 * at all — they only ever ask this service "how many meetings, which
 * Day combos", never encode day-pattern logic themselves.
 */
class MeetingPatternService
{
    public const TYPE_LECTURE = 'lecture';

    public const TYPE_LABORATORY = 'laboratory';

    public const TYPE_SPECIAL = 'special';

    /**
     * Subject Title/Code keywords that mark a Subject as a "Special"
     * subject (defaults to 1 meeting/week) regardless of its
     * lecture/laboratory hour split. Checked case-insensitively.
     *
     * @var list<string>
     */
    private const SPECIAL_KEYWORDS = [
        'NSTP',
        'ROTC',
        'CWTS',
        'PE ', // "PE 1", "PE 2" — trailing space avoids matching e.g. "TYPE"
        'P.E.',
        'PHYSICAL EDUCATION',
        'OJT',
        'ON-THE-JOB TRAINING',
        'THESIS',
        'CAPSTONE',
        'PRACTICUM',
        'INTERNSHIP',
        'SEMINAR',
    ];

    /**
     * Fallback meetings-per-week for each Subject Type, used whenever
     * config/scheduling.php doesn't override it.
     *
     * @var array<string, int>
     */
    private const DEFAULT_MEETINGS_PER_WEEK = [
        self::TYPE_LECTURE => 2,
        self::TYPE_LABORATORY => 1,
        self::TYPE_SPECIAL => 1,
    ];

    /**
     * Fallback ordered Day-combination candidates, keyed by meetings
     * per week. Every inner array's length MUST equal its key.
     *
     * Two-meeting patterns deliberately list the non-consecutive
     * Mon/Wed and Tue/Thu pairings first (per the Registrar's
     * "preferred day pairings" spec) and only fall back to a
     * consecutive-ish pairing (Wed/Fri) if nothing else is free.
     *
     * @var array<int, list<list<string>>>
     */
    private const DEFAULT_DAY_GROUPS = [
        1 => [
            ['Fri'],
            ['Sat'],
            ['Mon'],
            ['Tue'],
            ['Wed'],
            ['Thu'],
        ],
        2 => [
            ['Mon', 'Wed'],
            ['Tue', 'Thu'],
            ['Wed', 'Fri'],
            ['Mon', 'Fri'],
        ],
        // 3-meetings/week is intentionally defined here (future-proofing
        // per the spec) but is NEVER reached unless a future config
        // raises max_meetings_per_week to 3+ — see dayGroups() below.
        3 => [
            ['Mon', 'Wed', 'Fri'],
            ['Tue', 'Thu', 'Sat'],
        ],
    ];

    /**
     * Classify a Subject into Lecture / Laboratory / Special so the
     * right default meeting frequency can be looked up. Special
     * (keyword) subjects take priority over the hours-based check,
     * since e.g. "NSTP" or "Thesis Writing" may have hours recorded
     * either way but should still only meet once a week.
     */
    public function classify(Subject $subject): string
    {
        $haystack = ' '.mb_strtoupper(trim($subject->subject_title.' '.$subject->subject_code)).' ';

        foreach (self::SPECIAL_KEYWORDS as $keyword) {
            if (str_contains($haystack, mb_strtoupper($keyword))) {
                return self::TYPE_SPECIAL;
            }
        }

        $lecture = (int) $subject->lecture_hours;
        $laboratory = (int) $subject->laboratory_hours;

        // Purely a Laboratory subject (no lecture component) -> 1
        // continuous block/week. Subjects with BOTH a lecture and a
        // laboratory component are still scheduled as a Lecture for
        // meeting-pattern purposes (2 meetings/week); their lab hours
        // are additional weekly hours split across those meetings,
        // same as before.
        if ($laboratory > 0 && $lecture === 0) {
            return self::TYPE_LABORATORY;
        }

        return self::TYPE_LECTURE;
    }

    /**
     * How many times per week this Subject should meet. Config
     * (config/scheduling.php -> meeting_patterns.meetings_per_week.*)
     * can override the per-type default; max_meetings_per_week acts
     * as a hard ceiling so a future misconfiguration can never
     * accidentally reintroduce 3+ meeting/week schedules until that
     * ceiling is deliberately raised.
     */
    public function meetingsPerWeek(Subject $subject): int
    {
        $type = $this->classify($subject);

        $configured = config("scheduling.meeting_patterns.meetings_per_week.{$type}");
        $default = self::DEFAULT_MEETINGS_PER_WEEK[$type] ?? 1;
        $meetings = is_int($configured) && $configured > 0 ? $configured : $default;

        $max = (int) config('scheduling.meeting_patterns.max_meetings_per_week', 2);

        return max(1, min($meetings, $max));
    }

    /**
     * Ordered list of Day-combination candidates to try for this
     * Subject, best/most-preferred first. Every entry's length equals
     * meetingsPerWeek($subject). RecommendationService::recommendTimes()
     * iterates these in order, trying each against every candidate
     * Start Time, and simply moves to the next pattern if a
     * combination turns out to conflict — the existing "Smart Search"
     * fallback behavior is unchanged, only which patterns are offered.
     *
     * @return list<list<string>>
     */
    public function dayGroups(Subject $subject): array
    {
        $meetings = $this->meetingsPerWeek($subject);

        $configured = config("scheduling.meeting_patterns.day_groups.{$meetings}");
        if (is_array($configured) && ! empty($configured)) {
            return $configured;
        }

        return self::DEFAULT_DAY_GROUPS[$meetings] ?? [['Mon']];
    }

    /**
     * Human-readable label for the Subject's Type, surfaced in the
     * Auto Generate review panel / recommendation reasons so the
     * Registrar can see *why* a given meeting pattern was chosen.
     */
    public function label(Subject $subject): string
    {
        return match ($this->classify($subject)) {
            self::TYPE_LABORATORY => 'Laboratory',
            self::TYPE_SPECIAL => 'Special',
            default => 'Lecture',
        };
    }
}