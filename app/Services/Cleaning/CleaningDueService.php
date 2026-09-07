<?php

namespace App\Services\Cleaning;

use App\Models\CleaningCompletion;
use App\Models\CleaningTask;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Computes "what's due for a store on a date" + each item's status
 * (done / pending / overdue) by evaluating the rule and checking completions.
 * Nothing here is stored — it's always current.
 */
class CleaningDueService
{
    public function __construct(private readonly CleaningScheduleService $schedule)
    {
    }

    public function dueForStoreOnDate(int $storeId, CarbonInterface $date): Collection
    {
        $date = $date->copy()->startOfDay();

        $tasks = CleaningTask::query()
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $storeId))
            ->get();

        $due = $tasks
            ->filter(fn (CleaningTask $t) => $this->schedule->isDueOnDate($t, $date))
            ->values();

        // Load every completion these tasks need in TWO queries rather than two
        // per task: one for this period's rows, one for the historical counts.
        // Both are keyed by task id and handed to itemFor().
        $taskIds = $due->pluck('id')->all();
        $periodStarts = $due->mapWithKeys(fn (CleaningTask $t) => [
            $t->id => $this->schedule->periodBounds($t, $date)[0]->toDateString(),
        ])->all();

        $current = $this->currentCompletions($storeId, $taskIds, $periodStarts);
        $counts  = $this->completionCounts($storeId, $taskIds);

        return $due
            ->map(fn (CleaningTask $t) => $this->itemFor(
                $t,
                $date,
                $current[$t->id] ?? null,
                (int) ($counts[$t->id] ?? 0),
            ))
            ->values();
    }

    /**
     * This period's completion for each task, in one query.
     *
     * `period_start` differs per task (a daily task's period is one day, a weekly
     * task's is a Tue–Mon week), so the pairs are OR'd rather than filtered by a
     * single date.
     *
     * @param  int[]  $taskIds
     * @param  array<int, string>  $periodStarts  task id => period start date
     * @return array<int, CleaningCompletion>
     */
    private function currentCompletions(int $storeId, array $taskIds, array $periodStarts): array
    {
        if (empty($taskIds)) {
            return [];
        }

        return CleaningCompletion::query()
            ->with(['employees:id,first_name,middle_name,last_name', 'attachments:id,cleaning_completion_id,path'])
            ->where('store_id', $storeId)
            ->whereIn('cleaning_task_id', $taskIds)
            ->where(function ($q) use ($periodStarts) {
                foreach ($periodStarts as $taskId => $start) {
                    $q->orWhere(fn ($w) => $w
                        ->where('cleaning_task_id', $taskId)
                        ->whereDate('period_start', $start));
                }
            })
            ->get()
            ->keyBy('cleaning_task_id')
            ->all();
    }

    /**
     * How many completions each task has ever had, in one grouped query.
     *
     * This is what `has_history` is built from. The client used to call
     * /tasks/{task}/history once per task just to find out whether the history
     * icon should be rendered — N requests, each one deriving a full history
     * payload (completions plus computed overdue periods) to answer a boolean.
     *
     * @param  int[]  $taskIds
     * @return array<int, int>
     */
    private function completionCounts(int $storeId, array $taskIds): array
    {
        if (empty($taskIds)) {
            return [];
        }

        return CleaningCompletion::query()
            ->selectRaw('cleaning_task_id, COUNT(*) as aggregate')
            ->where('store_id', $storeId)
            ->whereIn('cleaning_task_id', $taskIds)
            ->groupBy('cleaning_task_id')
            ->pluck('aggregate', 'cleaning_task_id')
            ->all();
    }

    /**
     * History for one task+store: every real completion (always shown) plus
     * derived "overdue" periods within [from, to]. Ordered newest first.
     */
    public function historyForTask(CleaningTask $task, int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        $byPeriod = [];

        // 1) real completions — always included, regardless of date
        $completions = CleaningCompletion::query()
            ->with(['employees:id,first_name,middle_name,last_name', 'attachments:id,cleaning_completion_id,path'])
            ->where('cleaning_task_id', $task->id)
            ->where('store_id', $storeId)
            ->get();

        foreach ($completions as $c) {
            $key = $c->period_start->toDateString();
            $byPeriod[$key] = [
                'task_id'   => $task->id,
                'label'     => $task->name,
                'frequency' => $task->frequency,
                'period'    => [$key, $c->period_end->toDateString()],
                'status'    => 'done',
                'done_at'   => $c->completed_at?->toIso8601String(),
                'done_by'   => $c->employees->map(fn ($e) => trim("{$e->first_name} {$e->last_name}"))->values(),
                'has_photo' => $c->attachments->isNotEmpty(),
                'photos'    => $this->photoUrls($c),
                'note'      => $c->note,
            ];
        }

        // 2) derive missed (overdue) periods across the range that have no completion
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (!$this->schedule->isDueOnDate($task, $d)) {
                continue;
            }
            [$ps, $pe] = $this->schedule->periodBounds($task, $d);
            $key = $ps->toDateString();
            if (isset($byPeriod[$key])) {
                continue;
            }
            if ($pe->copy()->endOfDay()->lt(now())) {
                $byPeriod[$key] = [
                    'task_id'   => $task->id,
                    'label'     => $task->name,
                    'frequency' => $task->frequency,
                    'period'    => [$key, $pe->toDateString()],
                    'status'    => 'overdue',
                    'done_at'   => null,
                    'done_by'   => [],
                    'has_photo' => false,
                    'photos'    => [],
                    'note'      => null,
                ];
            }
        }

        return collect($byPeriod)
            ->sortByDesc(fn ($i) => $i['period'][0])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{date:string, items:array}>
     */
    public function dueRange(int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[] = [
                'date'  => $d->toDateString(),
                'items' => $this->dueForStoreOnDate($storeId, $d)->all(),
            ];
        }

        return $days;
    }

    /**
     * One row of the due list. Both completion lookups are passed in already
     * loaded — this method runs no queries, so the list costs a fixed number of
     * queries regardless of how many tasks a store has.
     */
    private function itemFor(
        CleaningTask $task,
        Carbon $date,
        ?CleaningCompletion $completion,
        int $completionsCount,
    ): array {
        [$periodStart, $periodEnd] = $this->schedule->periodBounds($task, $date);

        $status = $this->status($completion, $periodEnd);

        return [
            'task_id'        => $task->id,
            'label'          => $task->name,
            'description'    => $task->description,
            'frequency'      => $task->frequency,
            'weight'         => $task->weight,
            'photo_required' => (bool) $task->photo_required,
            'period'         => [$periodStart->toDateString(), $periodEnd->toDateString()],
            'status'         => $status,
            'completion_id'  => $completion?->id,
            'done_at'        => $completion?->completed_at?->toIso8601String(),
            'done_by'        => $completion
                ? $completion->employees->map(fn ($e) => trim("{$e->first_name} {$e->last_name}"))->values()
                : [],
            'has_photo'      => $completion ? $completion->attachments->isNotEmpty() : false,
            'photos'         => $completion ? $this->photoUrls($completion) : [],
            'note'           => $completion?->note,

            // Lets the client decide whether to offer the history view WITHOUT
            // calling /tasks/{task}/history for every task just to find out.
            //
            // NOTE the precise meaning: this counts recorded completions. The
            // history endpoint additionally derives "overdue" periods, which have
            // no row anywhere — so a task never completed but overdue for weeks
            // reports has_history = false while /history would still return
            // entries for it. `started_at_or_before_period` is included so a
            // client that wants "is there anything to look at" can widen the test
            // without another request.
            'has_history'                => $completionsCount > 0,
            'completions_count'          => $completionsCount,
            'started_at_or_before_period' => $task->starts_at
                ? $task->starts_at->copy()->startOfDay()->lte($periodStart)
                : false,
        ];
    }

    /**
     * Relative public URLs for a completion's photos. Relative (not absolute) so
     * the browser resolves them against the serving origin (works on any port /
     * host without depending on APP_URL).
     */
    private function photoUrls(CleaningCompletion $c): array
    {
        return $c->attachments
            ->map(fn ($a) => '/storage/' . ltrim($a->path, '/'))
            ->values()
            ->all();
    }

    private function status(?CleaningCompletion $completion, Carbon $periodEnd): string
    {
        if ($completion) {
            return 'done';
        }

        // overdue = the period's last day is already over and nothing was logged
        if ($periodEnd->copy()->endOfDay()->lt(now())) {
            return 'overdue';
        }

        return 'pending';
    }
}
