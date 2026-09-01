<?php

namespace App\Services\Cleaning;

use App\Models\CleaningTask;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Pure schedule math — no database writes. Given a task's recurrence rule,
 * answers "is it due on this date?" and "which period does this date fall in?".
 * Occurrences are never stored; this is evaluated on read.
 *
 * All week/period math comes from AccountingCalendarService: 13 periods x 4
 * weeks, week runs Tuesday -> Monday.
 *
 * ── "monthly" means once per PERIOD ──
 * A monthly task is due in ONE of the 4 weeks of each period. Which one is
 * derived from the task's `starts_at`: whichever week-in-period (1..4) that date
 * falls in, the task keeps that slot in every period. `interval` then skips
 * whole periods (interval=2 -> every other period).
 *
 * This replaced a calendar-month rule that returned true for EVERY day of a
 * matching month, which made a monthly task appear in all 4-5 weekly reports and
 * silently cost the store its weight in the ones nobody graded.
 */
class CleaningScheduleService
{
    public function __construct(private readonly AccountingCalendarService $calendar)
    {
    }

    public function isDueOnDate(CleaningTask $task, CarbonInterface $date): bool
    {
        $d     = $date->copy()->startOfDay();
        $start = $task->starts_at->copy()->startOfDay();

        if ($d->lt($start)) {
            return false;
        }
        if ($task->ends_at && $d->gt($task->ends_at->copy()->startOfDay())) {
            return false;
        }

        $n = max(1, (int) $task->interval);

        return match ($task->frequency) {
            'daily'   => ((int) $start->diffInDays($d)) % $n === 0,
            'weekly'  => $this->weeklyDue($task, $d, $start, $n),
            'monthly' => $this->monthlyDue($d, $start, $n),
            'hourly'  => true, // due every day within range (hour-blocks are a later refinement)
            default   => false,
        };
    }

    /**
     * Is the task due on ANY date inside the range? Used by the evaluation grid
     * to decide whether a task belongs to a report period at all.
     */
    public function isDueInRange(CleaningTask $task, CarbonInterface $from, CarbonInterface $to): bool
    {
        $cursor = $from->copy()->startOfDay();
        $last   = $to->copy()->startOfDay();

        while ($cursor->lte($last)) {
            if ($this->isDueOnDate($task, $cursor)) {
                return true;
            }
            $cursor->addDay();
        }

        return false;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}  [period_start, period_end] (dates)
     */
    public function periodBounds(CleaningTask $task, CarbonInterface $date): array
    {
        return match ($task->frequency) {
            // A weekly period is one accounting week: Tuesday -> Monday.
            'weekly'  => $this->accountingWeekBounds($date),
            // A "monthly" period is one accounting PERIOD: 4 accounting weeks.
            'monthly' => $this->accountingPeriodBounds($date),
            default   => [$date->copy()->startOfDay(), $date->copy()->startOfDay()], // daily / hourly = that single day
        };
    }

    // ── internals ──

    private function weeklyDue(CleaningTask $task, Carbon $d, Carbon $start, int $n): bool
    {
        // Count whole accounting weeks between the anchor week and this one.
        if ($this->accountingWeeksBetween($start, $d) % $n !== 0) {
            return false;
        }

        $days = $task->week_days ?? [];
        if (!empty($days)) {
            return in_array((int) $d->isoWeekday(), array_map('intval', $days), true);
        }

        return true; // no specific weekday → due any day that week
    }

    /**
     * Due once per period, in the week-in-period slot the task's `starts_at`
     * defines, every Nth period.
     */
    private function monthlyDue(Carbon $d, Carbon $start, int $n): bool
    {
        $startAt = $this->calendar->resolveDate($start);
        $dateAt  = $this->calendar->resolveDate($d);

        // Only the one week of the period that matches the anchor's slot.
        if ($dateAt['week_in_period'] !== $startAt['week_in_period']) {
            return false;
        }

        return $this->periodsBetween($startAt, $dateAt) % $n === 0;
    }

    private function accountingWeeksBetween(Carbon $a, Carbon $b): int
    {
        $from = $this->calendar->resolveDate($a);
        $to   = $this->calendar->resolveDate($b);

        return (int) round($from['from']->diffInDays($to['from']) / 7);
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function periodsBetween(array $from, array $to): int
    {
        $per = $this->calendar->periodsPerYearCount();

        return (($to['year'] * $per) + $to['period']) - (($from['year'] * $per) + $from['period']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function accountingWeekBounds(CarbonInterface $date): array
    {
        $r = $this->calendar->resolveDate($date);

        return [$r['from']->copy(), $r['to']->copy()];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function accountingPeriodBounds(CarbonInterface $date): array
    {
        $r = $this->calendar->resolveDate($date);

        return $this->calendar->periodRange($r['year'], $r['period']);
    }
}
