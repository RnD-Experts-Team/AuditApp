<?php

namespace App\Services\Cleaning;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The Little Caesars accounting calendar — 13 periods x 4 weeks, week runs
 * TUESDAY -> MONDAY. Pure date math, no database access.
 *
 * This is the ONLY place that knows what a "week" or a "period" is. Everything
 * else (due rules, the evaluation grid, reports) asks this service.
 *
 * IMPORTANT: the accounting week number is NOT the ISO week number. For 2026 the
 * two happen to be equal for all 52 weeks (ISO 2026-W01 starts 2025-12-29, one
 * day before our anchor), but the alignment breaks on 2026-12-29 — that Tuesday
 * opens accounting week 2027-W01 while ISO calls it 2026-W53. Never substitute
 * Carbon's isoWeek() for this service.
 */
class AccountingCalendarService
{
    /** How many weeks a given accounting year holds (52, or 53 when declared in config). */
    public function weeksInYear(int $year): int
    {
        return (int) (config('cleaning_calendar.weeks_in_year')[$year] ?? 52);
    }

    public function weeksPerPeriod(): int
    {
        return (int) config('cleaning_calendar.weeks_per_period', 4);
    }

    public function periodsPerYearCount(): int
    {
        return (int) config('cleaning_calendar.periods_per_year', 13);
    }

    /** First day (a Tuesday) of week 1 of the given accounting year. */
    public function yearAnchor(int $year): Carbon
    {
        $baseYear   = (int) config('cleaning_calendar.base_year');
        $baseAnchor = Carbon::parse(config('cleaning_calendar.base_anchor'))->startOfDay();

        if ($year === $baseYear) {
            return $baseAnchor->copy();
        }

        $anchor = $baseAnchor->copy();

        if ($year > $baseYear) {
            for ($y = $baseYear; $y < $year; $y++) {
                $anchor->addDays($this->weeksInYear($y) * 7);
            }
        } else {
            for ($y = $baseYear - 1; $y >= $year; $y--) {
                $anchor->subDays($this->weeksInYear($y) * 7);
            }
        }

        return $anchor;
    }

    /** First day (Tuesday) of the given accounting week. */
    public function weekStart(int $year, int $week): Carbon
    {
        $max = $this->weeksInYear($year);

        if ($week < 1 || $week > $max) {
            throw new InvalidArgumentException("Week {$week} does not exist in accounting year {$year} ({$max} weeks).");
        }

        return $this->yearAnchor($year)->addDays(($week - 1) * 7);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}  [Tuesday, Monday]
     */
    public function weekRange(int $year, int $week): array
    {
        $start = $this->weekStart($year, $week);

        return [$start, $start->copy()->addDays(6)];
    }

    /** Which period (1..13) an accounting week belongs to. */
    public function periodOf(int $week): int
    {
        return intdiv($week - 1, $this->weeksPerPeriod()) + 1;
    }

    /** Position of the week inside its period (1..4). */
    public function weekInPeriod(int $week): int
    {
        return (($week - 1) % $this->weeksPerPeriod()) + 1;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodRange(int $year, int $period): array
    {
        $per       = $this->weeksPerPeriod();
        $firstWeek = ($period - 1) * $per + 1;
        $lastWeek  = min($firstWeek + $per - 1, $this->weeksInYear($year));

        return [$this->weekStart($year, $firstWeek), $this->weekStart($year, $lastWeek)->addDays(6)];
    }

    /**
     * Which accounting week does a calendar date fall in?
     *
     * @return array{year:int, week:int, period:int, week_in_period:int, from:Carbon, to:Carbon}
     */
    public function resolveDate(CarbonInterface $date): array
    {
        $d = $date->copy()->startOfDay();

        // The date can belong to the accounting year before or after its Gregorian
        // one (week 1 of 2026 starts in December 2025), so try the neighbours too,
        // highest first — we want the latest year whose anchor is on or before $d.
        for ($year = $d->year + 1; $year >= $d->year - 1; $year--) {
            $anchor = $this->yearAnchor($year);

            if ($d->lt($anchor)) {
                continue;
            }

            $weeks = $this->weeksInYear($year);
            $end   = $anchor->copy()->addDays($weeks * 7 - 1);

            if ($d->gt($end)) {
                continue;
            }

            $week  = intdiv((int) $anchor->diffInDays($d), 7) + 1;
            $start = $anchor->copy()->addDays(($week - 1) * 7);

            return [
                'year'           => $year,
                'week'           => $week,
                'period'         => $this->periodOf($week),
                'week_in_period' => $this->weekInPeriod($week),
                'from'           => $start,
                'to'             => $start->copy()->addDays(6),
            ];
        }

        throw new InvalidArgumentException("Date {$d->toDateString()} falls outside the configured accounting calendar.");
    }

    /**
     * Step N accounting weeks from a given week, rolling across year boundaries.
     *
     * @return array{0:int, 1:int}  [year, week]
     */
    public function shiftWeek(int $year, int $week, int $delta): array
    {
        $week += $delta;

        while ($week < 1) {
            $year--;
            $week += $this->weeksInYear($year);
        }

        while ($week > $this->weeksInYear($year)) {
            $week -= $this->weeksInYear($year);
            $year++;
        }

        return [$year, $week];
    }
}
