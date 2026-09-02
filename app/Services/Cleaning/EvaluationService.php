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
 */
class EvaluationService
{
    public function __construct(
        private readonly CleaningScoringService $scoring,
        private readonly CleaningScheduleService $schedule,
        private readonly PeriodKeyService $periods,
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

        $tasksByStore  = $this->tasksByStore($storeIds);
        $allocsByStore = $this->allocationsByStore($storeIds, $periodType, $periodKey);

        // Whether a task is due in this period does not depend on the store, so
        // evaluate the (potentially slow) schedule math once per task.
        $dueCache = [];

        $rows = $stores->map(function (Store $store) use (
            $items, $evaluations, $tasksByStore, $allocsByStore, $from, $to, &$dueCache
        ) {
            $evaluation = $evaluations->get($store->id);
            $allocs     = $allocsByStore->get($store->id, collect());

            [$itemCells, $itemView] = $this->buildItemCells($items, $evaluation);
            $itemScore = $this->scoring->itemScore($itemCells);

            [$chart, $absent, $scoreInput] = $this->buildChart(
                $tasksByStore->get($store->id, collect()),
                $evaluation,
                $allocs,
                $from,
                $to,
                $dueCache
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
        array &$dueCache
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

            $chart[$task->frequency][] = [
                'task_id'         => $task->id,
                'name'            => $task->name,
                'base_weight'     => $base,
                'effective_weight' => $effective,
                // Kept as an alias for one release so existing clients don't break.
                'weight'          => $effective,
                'allocated_from'  => $this->allocationSources($allocations, $task->id, $tasks),
                'verdict'         => $verdict,
                'note'            => $verdictRow->note ?? null,
                'photos'          => $verdictRow ? $verdictRow->attachments->map(fn ($a) => '/storage/' . $a->path)->values() : [],
                'historical'      => $historical,
            ];

            $scoreInput[] = [
                'weight'  => $effective,
                'verdict' => $verdict,
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
     * @param  int[]  $storeIds
     */
    private function tasksByStore(array $storeIds): Collection
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
                ->map(fn ($s) => ['store_id' => $s->id, 'task' => $task]))
            ->groupBy('store_id')
            ->map(fn ($rows) => $rows->pluck('task'));
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
