<?php

namespace App\Services\Cleaning;

use App\Models\CleaningTask;
use App\Models\CleaningWeightAllocation;
use App\Models\Evaluation;
use App\Models\InspectionItem;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Builds the evaluation grid (rows = stores). Single source of truth used by
 * BOTH the grid endpoint and the report endpoints, so the numbers always match.
 *
 * ── Period awareness ──
 * A task only appears in a period's grid when it is actually DUE in that period.
 * Everything else assigned to the store lands in `absent_tasks` with its weight,
 * as a pool the auditor may hand to the tasks that are in play.
 *
 * Before this, every task assigned to a store appeared in every week, so a
 * monthly task sat ungraded in 3 of the 4 weeks of its period and quietly cost
 * the store its full weight each time.
 *
 * ── Effective weight ──
 * Scoring uses `base_weight + allocations targeting the task`, computed on read.
 * The `weight` column on a verdict row stays a historical snapshot only: the
 * auditor can reallocate after grading, and can allocate onto a task that has no
 * verdict row yet, so the snapshot cannot be the scoring input.
 *
 * ── Completion gate ──
 * A chart task the store has not marked complete is READ-ONLY to the auditor —
 * no pass, no fail, no N/A. There is nothing to judge, so the cell comes back
 * `evaluable = false` and the system alone decides it.
 *
 * What the system decides depends on the deadline, and the two conditions must
 * not be merged into one:
 *
 *   nothing logged, period still open  →  locked, left ungraded
 *                                         (the store still has time)
 *   nothing logged, period ended       →  locked AND failed, source 'system'
 *
 * Once late it has to be a fail rather than an empty cell, for two reasons that
 * both bite:
 *   · `finalize` refuses while anything is ungraded, so an un-gradable cell
 *     would mean that week could never be sent to the store at all; and
 *   · ungraded cells are already OUT of the score, so a store that logged
 *     nothing would score better than one that did the work badly.
 * See CleaningCompletionLookup for how "done" is measured.
 */
class EvaluationService
{
    public function __construct(
        private readonly CleaningScoringService $scoring,
        private readonly CleaningScheduleService $schedule,
        private readonly PeriodKeyService $periods,
        private readonly CleaningCompletionLookup $completions,
    ) {
    }

    /**
     * @param  int[]  $storeIds  stores the caller is allowed to see
     * @return array<string, mixed>
     */
    public function buildGrid(string $periodType, string $periodKey, array $storeIds): array
    {
        [$from, $to] = $this->periods->range($periodType, $periodKey);

        $items = InspectionItem::query()
            ->where('active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'weight']);

        $stores = Store::query()->whereIn('id', $storeIds)->orderBy('id')->get(['id', 'store']);
        $storeIds = $stores->pluck('id')->all();

        // ── Bulk-load everything the rows need (was one query per store) ──
        $evaluations = Evaluation::with(['itemValues.attachments', 'chartVerdicts.attachments'])
            ->whereIn('store_id', $storeIds)
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->get()
            ->keyBy('store_id');

        $assignmentsByStore = $this->taskAssignmentsByStore($storeIds);
        $allocsByStore      = $this->allocationsByStore($storeIds, $periodType, $periodKey);

        // Whether a task is due in this period does not depend on the store, so
        // evaluate the (potentially slow) schedule math once per task.
        $dueCache = [];

        // Whether the store DID the task obviously does depend on the store, so
        // this one is per row — but it costs a fixed two queries each, not one
        // per task.
        $enforceCompletion = $this->completions->isEnforced();

        $rows = $stores->map(function (Store $store) use (
            $items, $evaluations, $assignmentsByStore, $allocsByStore, $from, $to, &$dueCache, $enforceCompletion
        ) {
            $evaluation  = $evaluations->get($store->id);
            $allocs      = $allocsByStore->get($store->id, collect());
            $assignments = $assignmentsByStore->get($store->id, collect());
            $tasks       = $assignments->pluck('task');

            [$itemCells, $itemView] = $this->buildItemCells($items, $evaluation);
            $itemScore = $this->scoring->itemScore($itemCells);

            $coverage = $this->completions->coverageForPeriod(
                $store->id,
                $tasks,
                $from,
                $to,
                $assignments->pluck('assigned_at', 'task.id')->all(),
            );

            [$chart, $absent, $scoreInput] = $this->buildChart(
                $tasks,
                $evaluation,
                $allocs,
                $from,
                $to,
                $dueCache,
                $coverage,
                $enforceCompletion
            );
            $chartScore = $this->scoring->chartScore($scoreInput);

            $final        = $this->scoring->finalScore($itemScore, $chartScore);
            $completeness = $this->scoring->completeness(
                $itemScore,
                $chartScore,
                $items->count(),
                count($scoreInput)
            );

            // A finalized evaluation reports the numbers it was finalized WITH.
            // Otherwise retuning the shares — or any future formula change —
            // would silently rewrite a report a store has already been sent.
            $frozen = $evaluation && $evaluation->final_score !== null;

            return [
                'store_id'    => $store->id,
                'store'       => $store->store,

                'item_values' => $itemView,
                'item_score'  => $frozen ? (float) $evaluation->item_score : $itemScore['pct'],

                'chart'       => $chart,
                'chart_score' => $frozen ? (float) $evaluation->chart_score : $chartScore['pct'],
                'weight_lost' => $chartScore['lost'],

                // Roll-up of the completion gate, so the auditor can see at a
                // glance that a store is being auto-failed rather than having to
                // hover every locked cell to find out.
                //
                // Two numbers, because they answer different questions: how many
                // tasks the store did not log, and how many of those the SYSTEM
                // failed. They differ whenever the auditor took a locked cell and
                // marked it N/A or failed it himself.
                'completion_enforced' => $enforceCompletion,
                'tasks_not_completed' => collect($chart)->flatten(1)->where('evaluable', false)->count(),
                'tasks_auto_failed'   => collect($chart)->flatten(1)->where('verdict_source', 'system')->count(),
                'tasks_in_play'       => count($scoreInput),

                'absent_tasks' => $absent,
                'allocations'  => $allocs->map(fn ($a) => [
                    'source_task_id' => (int) $a->source_task_id,
                    'target_task_id' => (int) $a->target_task_id,
                    'amount'         => (int) $a->amount,
                ])->values(),

                // Auditor progress — deliberately separate from the store's score.
                'completion_pct' => $completeness['completion_pct'],
                'is_complete'    => $completeness['is_complete'],
                'graded_count'   => $completeness['graded_count'],
                'required_count' => $completeness['required_count'],
                'missing'        => $completeness['missing'],

                // The report number, computed here instead of in the browser.
                'commitment_pass' => $final['commitment_pass'],
                'final_score'     => $frozen ? (float) $evaluation->final_score : $final['pct'],
                'score_formula'   => $frozen ? $evaluation->score_formula : $final['formula'],
                'score_shares'    => ['items' => $final['items_share'], 'chart' => $final['chart_share']],
                'score_sides'     => $final['sides'],
                // Explains a 0% ITEM score; it no longer affects the final one.
                'item_has_auto_fail' => $final['item_has_auto_fail'],
                'score_frozen'    => $frozen,

                'finalized_at' => $evaluation?->finalized_at?->toIso8601String(),
                'finalized_by' => $evaluation?->finalized_by,
            ];
        })->values();

        return [
            'period_type' => $periodType,
            'period_key'  => $periodKey,
            'period'      => $this->periods->describe($periodType, $periodKey),
            'items'       => $items,
            'rows'        => $rows,
        ];
    }

    // ── internals ──

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     *         [cells for scoring, cells for the response]
     */
    private function buildItemCells(Collection $items, ?Evaluation $evaluation): array
    {
        $byItem = $evaluation ? $evaluation->itemValues->keyBy('inspection_item_id') : collect();

        $cells = [];
        $view  = [];

        foreach ($items as $item) {
            $cell  = $byItem->get($item->id);
            $value = $cell->value ?? 'empty';
            // Graded cells keep the weight they were graded at; ungraded ones
            // preview against the item's current weight.
            $weight = $cell ? (int) $cell->weight : (int) ($item->weight ?? 1);

            $cells[] = [
                'value'  => $value,
                'weight' => $weight,
                'ref'    => ['kind' => 'item', 'id' => $item->id, 'name' => $item->name],
            ];

            $view[$item->name] = [
                'value'  => $value,
                'weight' => $weight,
                'note'   => $cell->note ?? null,
                'photos' => $cell ? $cell->attachments->map(fn ($a) => '/storage/' . $a->path)->values() : [],
            ];
        }

        return [$cells, $view];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, mixed>, 2: array<int, mixed>}
     *         [chart grouped by frequency, absent tasks, scoring input]
     */
    private function buildChart(
        Collection $tasks,
        ?Evaluation $evaluation,
        Collection $allocations,
        $from,
        $to,
        array &$dueCache,
        array $coverage = [],
        bool $enforceCompletion = false
    ): array {
        $byTask = $evaluation ? $evaluation->chartVerdicts->keyBy('cleaning_task_id') : collect();

        // How much extra weight each task received this period.
        $received = $allocations->groupBy('target_task_id')
            ->map(fn ($rows) => (int) $rows->sum('amount'));
        $given = $allocations->groupBy('source_task_id')
            ->map(fn ($rows) => (int) $rows->sum('amount'));

        $chart      = ['daily' => [], 'weekly' => [], 'monthly' => [], 'hourly' => []];
        $absent     = [];
        $scoreInput = [];

        foreach ($tasks as $task) {
            $verdictRow = $byTask->get($task->id);

            // A deleted task is gone from future periods, but must not vanish
            // from the past ones where it was actually graded.
            if ($task->trashed() && $verdictRow === null) {
                continue;
            }

            if (!array_key_exists($task->id, $dueCache)) {
                $dueCache[$task->id] = $this->schedule->isDueInRange($task, $from, $to);
            }
            $isDue = $dueCache[$task->id];

            // A task that is not due but was already graded stays visible and
            // scored, so no report that has already been sent ever changes.
            $historical = !$isDue && $verdictRow !== null;

            if (!$isDue && !$historical) {
                if ((int) ($task->weight ?? 0) > 0) {
                    $allocated = (int) ($given[$task->id] ?? 0);
                    $absent[] = [
                        'task_id'     => $task->id,
                        'name'        => $task->name,
                        'frequency'   => $task->frequency,
                        'weight'      => (int) $task->weight,
                        'reason'      => 'not_due_this_period',
                        'allocated'   => $allocated,
                        'unallocated' => max(0, (int) $task->weight - $allocated),
                    ];
                }
                continue;
            }

            $base      = (int) ($task->weight ?? 0);
            $bonus     = (int) ($received[$task->id] ?? 0);
            $effective = $base + $bonus;
            $verdict   = $verdictRow->verdict ?? null;

            $cov = $coverage[$task->id] ?? [
                'expected' => 0, 'found' => 0, 'expected_period' => 0, 'found_period' => 0,
                'coverage_pct' => 100.0, 'can_pass' => true, 'missed' => false, 'done' => true,
                'status' => 'done', 'last_done_at' => null, 'done_by' => [], 'late' => false,
            ];

            // ── two different rules, deliberately not one ──
            //
            // Locked: the store has not logged the task, so there is nothing for
            // the auditor to judge and the cell is READ-ONLY — no pass, no fail,
            // no N/A. This does NOT wait for the deadline.
            //
            // Auto-failed: the deadline has also passed. A store still in time
            // has not failed anything, so a locked-but-not-yet-late cell simply
            // stays ungraded rather than being marked down.
            $locked   = $enforceCompletion && !$cov['can_pass'];
            $autoFail = $locked && $cov['missed'];

            // On a locked cell the auditor has NO say — the API refuses every
            // verdict, so a stored one cannot stand either. Such a row is either
            // legacy data from before this rule, or the store's completion was
            // undone after grading. Publishing it would show a result the system
            // would not accept today.
            //
            // So a task the store did not mark complete is entirely the system's
            // to decide: nothing while its deadline is open, `fail` once it has
            // passed.
            $effectiveVerdict = match (true) {
                $locked => $autoFail ? 'fail' : null,
                default => $verdict,
            };

            $source = match (true) {
                $locked                              => $autoFail ? 'system' : null,
                $verdict !== null && $verdict !== '' => $verdictRow->source ?? 'auditor',
                default                              => null,
            };

            $chart[$task->frequency][] = [
                'task_id'         => $task->id,
                'name'            => $task->name,
                'base_weight'     => $base,
                'effective_weight' => $effective,
                // Kept as an alias for one release so existing clients don't break.
                'weight'          => $effective,
                'allocated_from'  => $this->allocationSources($allocations, $task->id, $tasks),
                'verdict'         => $effectiveVerdict,
                'verdict_source'  => $source,
                'note'            => $verdictRow->note ?? null,
                'photos'          => $verdictRow ? $verdictRow->attachments->map(fn ($a) => '/storage/' . $a->path)->values() : [],
                'historical'      => $historical,

                // ── the completion gate ──
                // `_expected` / `_found` count the occurrences whose deadline has
                // PASSED; `_expected_period` / `_found_period` count everything
                // the period contains. The first pair explains an auto-fail, the
                // second explains why Pass is refused.
                'completion_expected'        => $cov['expected'],
                'completion_found'           => $cov['found'],
                'completion_expected_period' => $cov['expected_period'],
                'completion_found_period'    => $cov['found_period'],
                'completion_pct'             => $cov['coverage_pct'],
                'completion_status'          => $cov['status'],
                'completion_done'            => $cov['done'],
                'completion_late'            => $cov['late'],
                'last_done_at'               => $cov['last_done_at'],
                'done_by'                    => $cov['done_by'],
                // The two the client actually renders: disable Pass, say why.
                'evaluable'           => !$locked,
                'auto_failed'         => $autoFail,
                'lock_reason'         => match (true) {
                    !$locked                   => null,
                    !$autoFail                 => 'period_not_finished',
                    $cov['found_period'] > 0   => 'partially_completed',
                    default                    => 'not_completed',
                },
            ];

            $scoreInput[] = [
                'weight'  => $effective,
                'verdict' => $effectiveVerdict,
                'ref'     => ['kind' => 'chart', 'id' => $task->id, 'name' => $task->name],
            ];
        }

        return [$chart, $absent, $scoreInput];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allocationSources(Collection $allocations, int $taskId, Collection $tasks): array
    {
        return $allocations
            ->where('target_task_id', $taskId)
            ->map(fn ($a) => [
                'task_id' => (int) $a->source_task_id,
                'name'    => $tasks->firstWhere('id', $a->source_task_id)->name
                    ?? CleaningTask::withTrashed()->whereKey($a->source_task_id)->value('name'),
                'amount'  => (int) $a->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Every task assigned to any of the stores, keyed by store id.
     * `withTrashed()` so a task deleted after being graded still shows up in the
     * periods where it has a verdict.
     *
     * Each row carries `assigned_at` — the pivot's `created_at`, i.e. when this
     * task was attached to THIS store. The completion gate needs it: a task
     * added on Friday must not auto-fail the store for Tuesday to Thursday,
     * when it did not yet exist there.
     *
     * @param  int[]  $storeIds
     * @return Collection<int, Collection<int, array{store_id:int, task:CleaningTask, assigned_at:mixed}>>
     */
    private function taskAssignmentsByStore(array $storeIds): Collection
    {
        if (empty($storeIds)) {
            return collect();
        }

        return CleaningTask::withTrashed()
            ->whereHas('stores', fn ($q) => $q->whereIn('stores.id', $storeIds))
            ->with(['stores:id'])
            ->get()
            ->flatMap(fn (CleaningTask $task) => $task->stores
                ->whereIn('id', $storeIds)
                ->map(fn ($s) => [
                    'store_id'    => $s->id,
                    'task'        => $task,
                    'assigned_at' => $s->pivot->created_at ?? null,
                ]))
            ->groupBy('store_id');
    }

    /**
     * @param  int[]  $storeIds
     */
    private function allocationsByStore(array $storeIds, string $periodType, string $periodKey): Collection
    {
        if (empty($storeIds)) {
            return collect();
        }

        return CleaningWeightAllocation::query()
            ->whereIn('store_id', $storeIds)
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->get()
            ->groupBy('store_id');
    }
}
