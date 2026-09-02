<?php

return [
    /*
    | ── The Little Caesars accounting calendar ──
    |
    | 13 periods x 4 weeks = 52 weeks. A week runs TUESDAY -> MONDAY.
    | This is NOT the Gregorian calendar: "monthly" in this codebase means
    | "once per PERIOD (4 weeks)", never "once per calendar month".
    |
    | The whole calendar is derived from ONE anchor: the first day (a Tuesday)
    | of week 1 of `base_year`. Every other year is walked forward/backward from
    | it using `weeks_in_year`.
    |
    | Verified against the printed "Little Caesars 2026 Accounting Calendar":
    |   Week  1 = 2025-12-30 .. 2026-01-05  (Period 1)
    |   Week 33 = 2026-08-11 .. 2026-08-17  (Period 9, week 1 of 4)
    |   Week 52 = 2026-12-22 .. 2026-12-28  (Period 13)
    */
    'base_year'   => 2026,
    'base_anchor' => '2025-12-30',   // Tuesday

    'weeks_per_period' => 4,
    'periods_per_year' => 13,

    /*
    | A 52-week year is 364 days, so the calendar drifts ~1.25 days a year against
    | the Gregorian one. Every 5-6 years a 53-week year is inserted to correct it.
    |
    | Declare those years HERE — never in code. Any year not listed is 52 weeks.
    | Getting this wrong shifts every report from that year onward, so confirm the
    | year against the printed calendar before adding it.
    */
    'weeks_in_year' => [
        // 2031 => 53,
    ],

    /*
    | Weight of the "cleaning chart commitment" point in the final report score.
    |
    | The client's Excel formula is (passed items + commitment) / (items + 1) —
    | i.e. the WHOLE cleaning chart is worth exactly one item. Keep this at 1 to
    | reproduce that number; raise it to make the chart count for more.
    */
    'commitment_weight' => (int) env('CLEANING_COMMITMENT_WEIGHT', 1),
];
