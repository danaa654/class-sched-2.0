<?php

/**
 * Auto Generate scheduling configuration.
 *
 * MeetingPatternService reads all of this. Nothing else in the
 * scheduling engine (RecommendationService, AutoScheduleService) needs
 * to change when these values change.
 */
return [

    'meeting_patterns' => [

        /*
        |----------------------------------------------------------------
        | Max meetings per week
        |----------------------------------------------------------------
        | Hard ceiling on meetings/week the Auto Generate engine will
        | ever produce, regardless of any override below. Raise this to
        | 3 (and add a 3 => [...] entry to `day_groups` if you want a
        | custom pairing list) to re-enable MWF/TThS-style schedules in
        | the future. Leave at 2 to keep the "no 3-meetings/week unless
        | explicitly enabled" rule in force.
        */
        'max_meetings_per_week' => 3,

        /*
        |----------------------------------------------------------------
        | Max continuous hours per meeting
        |----------------------------------------------------------------
        | Used to make meeting frequency HOUR-AWARE: if a Subject's
        | total weekly hours (lecture + laboratory) wouldn't fit in a
        | single meeting of this length, MeetingPatternService bumps
        | the meeting count up (capped by max_meetings_per_week above)
        | instead of proposing one unrealistically long block. E.g. a
        | 4-hour/week Capstone (Type "special", 1x/week default)
        | becomes 2 meetings of 2 hours each rather than one 4-hour
        | block. Set to 0 to disable this bump entirely.
        |
        | Raised to 5 so Special subjects (Capstone, Thesis, OJT,
        | Practicum, ...) with up to 5 hours/week — e.g. CAP102's
        | 2 lecture + 3 laboratory hours — can still legitimately be
        | scheduled as ONE continuous block/week instead of always
        | being bumped to 2x/week and flagged as a pattern mismatch.
        | Subjects needing more than 5 continuous hours still get
        | bumped to additional meetings as before.
        */
        'max_continuous_hours' => 5,

        /*
        |----------------------------------------------------------------
        | Meetings per week, per Subject Type
        |----------------------------------------------------------------
        | Overrides MeetingPatternService::DEFAULT_MEETINGS_PER_WEEK.
        | Keys: 'lecture', 'laboratory', 'special'. Omit a key to keep
        | its built-in default (lecture=2, laboratory=1, special=1).
        | Still capped by max_meetings_per_week above.
        |
        | Example — allow 3 meetings/week for lecture subjects once
        | that's ready to ship:
        |   'meetings_per_week' => ['lecture' => 3],
        |   'max_meetings_per_week' => 3,
        */
        'meetings_per_week' => [
            // 'lecture' => 2,
            // 'laboratory' => 1,
            // 'special' => 1,
        ],

        /*
        |----------------------------------------------------------------
        | Day-combination candidates, per meetings-per-week count
        |----------------------------------------------------------------
        | Overrides MeetingPatternService::DEFAULT_DAY_GROUPS. Each
        | array must be keyed by the exact meetings/week count it
        | applies to, and every inner combination must contain that
        | many Day tokens (Mon/Tue/Wed/Thu/Fri/Sat/Sun). Listed in
        | priority order — earliest entry is tried first.
        |
        | Example — custom 2-meeting priority:
        |   'day_groups' => [
        |       2 => [['Mon', 'Thu'], ['Tue', 'Fri']],
        |   ],
        */
        'day_groups' => [
            // 1 => [['Fri'], ['Sat']],
            // 2 => [['Mon', 'Wed'], ['Tue', 'Thu']],
            // 3 => [['Mon', 'Wed', 'Fri'], ['Tue', 'Thu', 'Sat']],
        ],

    ],

];