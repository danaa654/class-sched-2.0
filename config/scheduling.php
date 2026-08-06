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
        'max_meetings_per_week' => 2,

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