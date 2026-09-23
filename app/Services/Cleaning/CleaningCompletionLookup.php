<?php

namespace App\Services\Cleaning;

use App\Models\CleaningCompletion;
use App\Models\CleaningSetting;
use App\Models\CleaningTask;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * "Did the store actually DO this task in this report period?"
 *
 * The evaluation grid used to have no way to ask that question — it read the
 * recurrence rule (is the task DUE?) and never looked at `cleaning_completions`
 * at all, so an auditor could mark `pass` on work nobody logged. This class is
 * the missing link between the two tracks.
 *
 * ── Why this is not a date-range query ──
 * `cleaning_completions.period_start` is the start of THE TASK'S OWN period, and
 * that period depends on the task's frequency:
 *
 *   daily / hourly  →  the single day
 *   weekly          →  that accounting week's Tuesday
 *   monthly         →  the first day of the 4-week accounting PERIOD
 *
 * The report period, meanwhile, is one accounting WEEK. So a monthly task's
 * completion row normally carries a `period_start` from up to three weeks BEFORE
 * the week being reported. The obvious query
 *
 *     whereBetween('period_start', [$from, $to])       // ❌
 *
 * therefore finds nothing for monthly tasks and would auto-fail every one of
 * them, silently. Instead we resolve each task's own period starts first and
 * match on those exact values — the same approach as
 * CleaningDueService::currentCompletions().
 *
 * ── Coverage, not a boolean ──
 * A daily task is due seven times inside one report week, so "done" is a
 * fraction. The configured rule turns that fraction into a yes/no — see
 * CleaningSetting: completion_rule = all | any | threshold.
 *
 * ── Two counts, because they answer two different questions ──
 * `expected_period` / `found_period`  every occurrence the period contains
 * `expected` / `found`                only those whose deadline has PASSED
 *
 * They drive different rules, and collapsing them into one was a real bug:
 *
 *   can_pass  ← the whole period. Passing a task requires a completion whether
 *               or not the deadline has arrived: with nothing logged there is
 *               nothing to judge, so `pass` is refused immediately.
 *   missed    ← the ended subset only. A store still in time has not failed
 *               anything, so it is never auto-failed mid-period.
 *
 * That yields three states rather than two: the store logged it (normal), it
 * has not logged it but still has time (pass refused, nothing failed), or the
 * deadline passed with nothing logged (pass refused AND auto-failed).
 */
class CleaningCompletionLookup
{
    public function __construct(private readonly CleaningScheduleService $schedule)
    {
    }

    /**
     * Is the "not completed → cannot pass" rule switched on at all?
     *
     * A master switch, so the whole feature can be turned off from one settings
     * row if it turns out a client's stores log far less reliably than they
     * believe. Better than a rushed deploy under pressure.
     */
    public function isEnforced(): bool
    {
        return CleaningSetting::getBool('chart_requires_completion');
    }

    /**
     * Completion coverage for every given task against ONE report period.
     *
     * Costs a fixed two queries regardless of how many tasks are passed in —
     * buildGrid() calls this once per store row, so per-task queries here would
     * turn one grid into hundreds of round trips.
     *
     * @param  Collection<int, CleaningTask>  $tasks
     * @param  array<int, mixed>  $assignedAt  task id => when the task was attached to
     *                                         this store. Occurrences that fell due
     *                                         BEFORE the task was assigned were never
     *                                         the store's to do, so they are not counted.
     * @return array<int, array{expected:int, found:int, expected_period:int, found_period:int,
     *                          coverage_pct:float, can_pass:bool, missed:bool, done:bool,
     *                          status:string, last_done_at:?string, done_by:array<int,string>,
     *                          late:bool}>
     */
    public function coverageForPeriod(
        int $storeId,
        Collection $tasks,
        CarbonInterface $from,
        CarbonInterface $to,
        array $assignedAt = [],
    ): array {
        $starts      = $this->expectedPeriodStarts($tasks, $from, $to, $assignedAt);
        $completions = $this->completionsFor($storeId, $starts);

        $out = [];

        foreach ($tasks as $task) {
            $all   = $starts[$task->id]['all'] ?? [];
            $ended = $starts[$task->id]['ended'] ?? [];
            $rows  = $completions[$task->id] ?? [];

            $matchedAll   = array_values(array_intersect_key($rows, array_flip($all)));
            $matchedEnded = array_values(array_intersect_key($rows, array_flip($ended)));

            $expectedPeriod = count($all);
            $foundPeriod    = count($matchedAll);
            $expectedEnded  = count($ended);
            $foundEnded     = count($matchedEnded);

            // Passing needs a completion regardless of the deadline: nothing
            // logged means there is nothing for the auditor to judge.
            $canPass = $expectedPeriod === 0 || $this->passesRule($expectedPeriod, $foundPeriod);

            // Auto-failing needs the deadline to have passed. A store still in
            // time has not failed anything.
            $missed = $expectedEnded > 0 && !$this->passesRule($expectedEnded, $foundEnded);

            $out[$task->id] = [
                // the whole period — drives can_pass
                'expected_period' => $expectedPeriod,
                'found_period'    => $foundPeriod,
                // only the occurrences whose deadline has passed — drives missed
                'expected'        => $expectedEnded,
                'found'           => $foundEnded,

                'coverage_pct' => $expectedPeriod > 0 ? round($foundPeriod / $expectedPeriod * 100, 1) : 100.0,
                'can_pass'     => $canPass,
                'missed'       => $missed,
                // Kept for readers that only want "is this settled": the store
                // has done everything the period asks of it.
                'done'         => $canPass,
                'status'       => $this->status($canPass, $missed, $foundPeriod),
                'last_done_at' => $this->lastDoneAt($matchedAll),
                'done_by'      => $this->doneBy($matchedAll),
                'late'         => $this->anyLate($matchedAll),
            ];
        }

        return $out;
    }

    // ── internals ──

    /**
     * Which of the task's own periods fall due inside the report period, split
     * into everything the period contains and the subset already overdue.
     *
     * Returned as unique date strings: a weekly task's rule matches several days
     * of the week but they all resolve to the SAME Tue→Mon period, and that is
     * one expected completion, not five.
     *
     * `ended` = the occurrence's own period has fully closed, which is the same
     * definition of "overdue" that CleaningDueService::status() already uses.
     * Only those can be held against the store:
     *
     *   Thursday of an open week, daily task  →  all 4, ended 2 (Tue, Wed)
     *   Thursday of an open week, weekly task →  all 1, ended 0
     *   any completed past week, daily task   →  all 7, ended 7
     *
     * @param  Collection<int, CleaningTask>  $tasks
     * @param  array<int, mixed>  $assignedAt
     * @return array<int, array{all:array<int,string>, ended:array<int,string>}>
     */
    private function expectedPeriodStarts(
        Collection $tasks,
        CarbonInterface $from,
        CarbonInterface $to,
        array $assignedAt,
    ): array {
        $first = $from->copy()->startOfDay();
        $last  = $to->copy()->startOfDay();
        $now   = Carbon::now();
        $out   = [];

        foreach ($tasks as $task) {
            // A task assigned to this store mid-period cannot owe the days that
            // passed before it existed here. Without this floor, creating a task
            // on Friday would auto-fail the store for Tuesday to Thursday.
            $floor = $this->assignedFloor($assignedAt[$task->id] ?? null);
            $all   = [];
            $ended = [];

            for ($d = $first->copy(); $d->lte($last); $d->addDay()) {
                if ($floor && $d->lt($floor)) {
                    continue;
                }
                if (!$this->schedule->isDueOnDate($task, $d)) {
                    continue;
                }

                [$periodStart, $periodEnd] = $this->schedule->periodBounds($task, $d);
                $key = $periodStart->toDateString();

                $all[$key] = true;

                if ($periodEnd->copy()->endOfDay()->lt($now)) {
                    $ended[$key] = true;
                }
            }

            $out[$task->id] = ['all' => array_keys($all), 'ended' => array_keys($ended)];
        }

        return $out;
    }

    /**
     * The completions that could possibly match, in ONE query.
     *
     * The (task, period_start) pairs are OR'd rather than filtered by a plain
     * date range — see the class docblock for why a range is wrong.
     *
     * Queried against the FULL set of period starts — the overdue subset is a
     * subset of it, so one query serves both counts.
     *
     * @param  array<int, array{all:array<int,string>, ended:array<int,string>}>  $expectedStarts
     * @return array<int, array<string, CleaningCompletion>>  task id => period start => row
     */
    private function completionsFor(int $storeId, array $expectedStarts): array
    {
        $pairs = array_filter(
            array_map(fn ($starts) => $starts['all'] ?? [], $expectedStarts),
            fn ($starts) => !empty($starts),
        );

        if (empty($pairs)) {
            return [];
        }

        $rows = CleaningCompletion::query()
            ->with('employees:id,first_name,middle_name,last_name')
            ->where('store_id', $storeId)
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $taskId => $starts) {
                    $q->orWhere(fn ($w) => $w
                        ->where('cleaning_task_id', $taskId)
                        ->whereIn('period_start', $starts));
                }
            })
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->cleaning_task_id][$row->period_start->toDateString()] = $row;
        }

        return $out;
    }

    /**
     * Turn coverage into a yes/no using the configured rule.
     *
     * `all` is the shipped default: a daily task marked on 4 of 7 days is not
     * done. The rule lives in `cleaning_settings` so it can be relaxed to `any`
     * or a threshold without a deploy.
     */
    private function passesRule(int $expected, int $found): bool
    {
        if ($expected <= 0) {
            // Nothing was owed this period — there is nothing to fail for.
            return true;
        }

        return match ((string) CleaningSetting::get('completion_rule')) {
            'any'       => $found > 0,
            'threshold' => ($found / $expected * 100) >= max(0, CleaningSetting::getInt('completion_threshold')),
            default     => $found >= $expected,   // 'all'
        };
    }

    /**
     * done     the store has done everything this period asks of it
     * pending  not yet — but the deadline has not passed, so nothing is failed
     * partial  the deadline passed on some of it, and some was logged
     * missing  the deadline passed and nothing was logged
     */
    private function status(bool $canPass, bool $missed, int $foundPeriod): string
    {
        return match (true) {
            $canPass             => 'done',
            !$missed             => 'pending',
            $foundPeriod > 0     => 'partial',
            default              => 'missing',
        };
    }

    /**
     * @param  array<int, CleaningCompletion>  $matched
     */
    private function lastDoneAt(array $matched): ?string
    {
        $latest = null;

        foreach ($matched as $row) {
            if ($row->completed_at && (!$latest || $row->completed_at->gt($latest))) {
                $latest = $row->completed_at;
            }
        }

        return $latest?->toIso8601String();
    }

    /**
     * Everyone credited on this period's completions, de-duplicated.
     *
     * @param  array<int, CleaningCompletion>  $matched
     * @return array<int, string>
     */
    private function doneBy(array $matched): array
    {
        $names = [];

        foreach ($matched as $row) {
            foreach ($row->employees as $employee) {
                $names[] = trim("{$employee->first_name} {$employee->last_name}");
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * Was anything logged after its period had already ended?
     *
     * A late completion still COUNTS — refusing it would punish a store for the
     * clock rather than for the cleaning. But the auditor should be able to see
     * it, so it is reported rather than hidden.
     *
     * @param  array<int, CleaningCompletion>  $matched
     */
    private function anyLate(array $matched): bool
    {
        foreach ($matched as $row) {
            if ($row->completed_at && $row->period_end
                && $row->completed_at->gt($row->period_end->copy()->endOfDay())) {
                return true;
            }
        }

        return false;
    }

    private function assignedFloor(mixed $assignedAt): ?Carbon
    {
        if (empty($assignedAt)) {
            return null;
        }

        return $assignedAt instanceof CarbonInterface
            ? $assignedAt->copy()->startOfDay()
            : Carbon::parse((string) $assignedAt)->startOfDay();
    }
}
