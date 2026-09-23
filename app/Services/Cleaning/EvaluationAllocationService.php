<?php

namespace App\Services\Cleaning;

use App\Models\CleaningTask;
use App\Models\CleaningWeightAllocation;
use App\Models\Evaluation;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies one store's weight distribution onto other stores, for the same period.
 *
 * ── Why this is only a few dozen lines ──
 * A monthly task is not due in three of its four weeks, so its weight is out of
 * play and the auditor hands it to the tasks that ARE in play. He did that for
 * store 1; now he wants the same split on the other stores without retyping it.
 *
 * Tasks are shared between stores (one `cleaning_tasks` row, attached to many
 * stores) and `weight` is a column on the task, not on the pivot. So the task
 * ids and their weights are IDENTICAL across stores, and copying a split is
 * copying the same rows with a different `store_id`. Nothing has to be
 * recalculated.
 *
 * ── What it will not do ──
 * A split that would be invalid on the target store is skipped, with a reason,
 * and the rest of the copy still runs. It never guesses, and it never writes
 * half a split: an allocation whose amounts do not add up to the absent task's
 * weight is a silently wrong report, which is worse than no allocation at all.
 *
 * Every check here is the same one EvaluationAllocationController::store()
 * applies to a manual save — deliberately, so a copy can never produce a split
 * the manual screen would have rejected.
 */
class EvaluationAllocationService
{
    public function __construct(private readonly EvaluationService $evaluations)
    {
    }

    /**
     * @param  int[]  $targetStoreIds
     * @return array<string, mixed>
     */
    public function copy(
        int $sourceStoreId,
        array $targetStoreIds,
        string $periodType,
        string $periodKey,
        bool $dryRun,
        ?int $userId = null,
    ): array {
        $splits = $this->sourceSplits($sourceStoreId, $periodType, $periodKey);

        $sourceRow = $this->evaluations
            ->buildGrid($periodType, $periodKey, [$sourceStoreId])['rows']
            ->first();

        $summary = [
            'dry_run' => $dryRun,
            'source'  => [
                'store_id' => $sourceStoreId,
                'store'    => $sourceRow['store'] ?? null,
                'splits'   => $splits->count(),
                'rows'     => $splits->flatten(1)->count(),
            ],
            'period'  => ['period_type' => $periodType, 'period_key' => $periodKey],
            'results' => [],
        ];

        // Nothing to copy is not an error — it means the auditor has not built a
        // split on the source store yet. Say so plainly instead of failing.
        if ($splits->isEmpty()) {
            $summary['results'] = collect($targetStoreIds)
                ->map(fn ($id) => ['store_id' => (int) $id, 'copied' => 0, 'skipped' => [
                    ['reason' => 'nothing_to_copy', 'detail' => 'The source store has no split for this period.'],
                ]])
                ->all();

            return $summary;
        }

        $targetRows = $this->evaluations
            ->buildGrid($periodType, $periodKey, $targetStoreIds)['rows']
            ->keyBy('store_id');

        foreach ($targetStoreIds as $targetStoreId) {
            $targetStoreId = (int) $targetStoreId;

            $summary['results'][] = $this->copyToStore(
                $splits,
                $targetStoreId,
                $targetRows->get($targetStoreId),
                $periodType,
                $periodKey,
                $dryRun,
                $userId,
            );
        }

        return $summary;
    }

    /**
     * Clear saved splits across many stores at once — the mirror of copy().
     *
     * The copy is bulk but the delete was not: undoing a copy onto 8 stores
     * meant 24 separate DELETE calls from the client, one per store per split.
     *
     * ── "clear", not "undo" ──
     * This removes what is there now. It does NOT restore whatever a store had
     * before a copy overwrote it — the copy deletes the old rows, and we keep no
     * snapshot of them. A real undo means storing every replaced split, which is
     * a new table for a case that barely happens (the auditor copies precisely
     * because the other stores are empty). Deliberate limitation, written down
     * so nobody assumes otherwise.
     *
     * Removing is always safe: an absent task's weight simply goes back to being
     * out of play, which is exactly the state when nobody has allocated at all.
     * It can never leave a period half-valid.
     *
     * @param  int[]  $storeIds
     * @param  int[]  $sourceTaskIds  empty = every split in the period
     * @return array<string, mixed>
     */
    public function remove(
        array $storeIds,
        string $periodType,
        string $periodKey,
        array $sourceTaskIds,
        bool $dryRun,
    ): array {
        // Deliberately NOT buildGrid(): removing needs no due/in-play checks, and
        // building a full grid for up to 50 stores to delete some rows would be
        // pure waste. Three cheap reads instead.
        $names     = Store::query()->whereIn('id', $storeIds)->pluck('store', 'id');
        $finalized = Evaluation::query()
            ->whereIn('store_id', $storeIds)
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->whereNotNull('finalized_at')
            ->pluck('store_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $rows = $this->allocationQuery($storeIds, $periodType, $periodKey, $sourceTaskIds)
            ->get()
            ->groupBy('store_id');

        $taskNames = CleaningTask::withTrashed()
            ->whereIn('id', $rows->flatten(1)->pluck('source_task_id')->unique()->all())
            ->pluck('name', 'id');

        $results   = [];
        $clearable = [];

        foreach ($storeIds as $storeId) {
            $storeId = (int) $storeId;
            $result  = ['store_id' => $storeId, 'store' => $names[$storeId] ?? null, 'removed' => 0, 'skipped' => []];

            // The report went out with these numbers. Clearing the split would
            // change the score a store has already been shown.
            if (in_array($storeId, $finalized, true)) {
                $result['skipped'][] = ['reason' => 'evaluation_already_finalized',
                    'detail' => 'Reopen the evaluation first if this really needs to change.'];
                $results[] = $result;
                continue;
            }

            $splits = ($rows->get($storeId) ?? collect())->groupBy('source_task_id');

            if ($splits->isEmpty()) {
                $result['skipped'][] = ['reason' => 'nothing_to_remove',
                    'detail' => 'This store has no saved split for this period.'];
                $results[] = $result;
                continue;
            }

            $result['removed'] = $splits->count();
            $result['splits']  = $splits->map(fn ($group, $sourceTaskId) => [
                'source_task_id' => (int) $sourceTaskId,
                'name'           => $taskNames[$sourceTaskId] ?? $this->taskName((int) $sourceTaskId),
                'amount'         => (int) $group->sum('amount'),
                'targets'        => $group->count(),
            ])->values()->all();

            $clearable[] = $storeId;
            $results[]   = $result;
        }

        if (!$dryRun && !empty($clearable)) {
            DB::transaction(function () use ($clearable, $periodType, $periodKey, $sourceTaskIds) {
                $this->allocationQuery($clearable, $periodType, $periodKey, $sourceTaskIds)->delete();
            });
        }

        return [
            'dry_run' => $dryRun,
            'period'  => ['period_type' => $periodType, 'period_key' => $periodKey],
            'scope'   => empty($sourceTaskIds) ? 'all_splits' : 'selected_tasks',
            'results' => $results,
        ];
    }

    // ── internals ──

    /**
     * @param  int[]  $storeIds
     * @param  int[]  $sourceTaskIds
     */
    private function allocationQuery(
        array $storeIds,
        string $periodType,
        string $periodKey,
        array $sourceTaskIds,
    ) {
        return CleaningWeightAllocation::query()
            ->whereIn('store_id', $storeIds)
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->when(!empty($sourceTaskIds), fn ($q) => $q->whereIn('source_task_id', $sourceTaskIds));
    }

    /**
     * The source store's split for this period, grouped by the absent task that
     * is giving its weight away.
     *
     * @return Collection<int, Collection<int, CleaningWeightAllocation>>
     */
    private function sourceSplits(int $storeId, string $periodType, string $periodKey): Collection
    {
        return CleaningWeightAllocation::query()
            ->where('store_id', $storeId)
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->get()
            ->groupBy('source_task_id');
    }

    /**
     * @param  Collection<int, Collection<int, CleaningWeightAllocation>>  $splits
     * @param  array<string, mixed>|null  $row  the target store's grid row
     * @return array<string, mixed>
     */
    private function copyToStore(
        Collection $splits,
        int $targetStoreId,
        ?array $row,
        string $periodType,
        string $periodKey,
        bool $dryRun,
        ?int $userId,
    ): array {
        $result = ['store_id' => $targetStoreId, 'store' => $row['store'] ?? null, 'copied' => 0, 'skipped' => []];

        if (!$row) {
            $result['skipped'][] = ['reason' => 'store_not_visible', 'detail' => 'Store not found in this grid.'];

            return $result;
        }

        // The report has already been sent with these numbers. Changing them now
        // would mean telling a store one score and showing another.
        if (!empty($row['finalized_at'])) {
            $result['skipped'][] = ['reason' => 'evaluation_already_finalized',
                'detail' => 'Reopen the evaluation first if this really needs to change.'];

            return $result;
        }

        $absent = collect($row['absent_tasks'] ?? [])->keyBy('task_id');
        $inPlay = collect($row['chart'] ?? [])->flatten(1)
            ->reject(fn ($t) => $t['historical'] ?? false)
            ->keyBy('task_id');

        $planned = [];

        foreach ($splits as $sourceTaskId => $rows) {
            $sourceTaskId = (int) $sourceTaskId;
            $source       = $absent->get($sourceTaskId);
            $name         = $source['name'] ?? $this->taskName($sourceTaskId);

            // Either the task is not assigned to this store at all, or it IS due
            // here this period — in which case it keeps its own weight and has
            // nothing to give away.
            if (!$source) {
                $result['skipped'][] = [
                    'reason'         => 'source_task_not_absent_here',
                    'source_task_id' => $sourceTaskId,
                    'detail'         => "\"{$name}\" is not an absent task for this store this period.",
                ];
                continue;
            }

            $missingTarget = $rows->first(fn ($a) => !$inPlay->has((int) $a->target_task_id));
            if ($missingTarget) {
                $result['skipped'][] = [
                    'reason'         => 'target_task_not_in_play',
                    'source_task_id' => $sourceTaskId,
                    'target_task_id' => (int) $missingTarget->target_task_id,
                    'detail'         => 'One of the receiving tasks is not gradable for this store this period, '
                        . 'so the weight would vanish.',
                ];
                continue;
            }

            // Weight lives on the shared task row, so this should always match —
            // but a split that does not add up is exactly the silent error this
            // whole feature must not introduce, so it is checked rather than
            // assumed.
            $total = (int) $rows->sum('amount');
            if ($total !== (int) $source['weight']) {
                $result['skipped'][] = [
                    'reason'         => 'split_does_not_match_weight',
                    'source_task_id' => $sourceTaskId,
                    'detail'         => "The split adds up to {$total} but \"{$name}\" is worth "
                        . "{$source['weight']} here.",
                ];
                continue;
            }

            $planned[$sourceTaskId] = $rows
                ->map(fn ($a) => ['target_task_id' => (int) $a->target_task_id, 'amount' => (int) $a->amount])
                ->values()
                ->all();
        }

        $result['copied'] = count($planned);
        $result['splits'] = collect($planned)->map(fn ($rows, $sourceTaskId) => [
            'source_task_id' => (int) $sourceTaskId,
            'name'           => $absent->get((int) $sourceTaskId)['name'] ?? null,
            'targets'        => $rows,
            // Worth surfacing: the copy REPLACES whatever this store had.
            // Merging two splits cannot be done safely — the amounts have to add
            // up to exactly the source task's weight, and two merged splits
            // would not.
            'replaces_existing' => $this->hasExistingSplit($targetStoreId, $periodType, $periodKey, (int) $sourceTaskId),
        ])->values()->all();

        if (!$dryRun && !empty($planned)) {
            $this->write($targetStoreId, $periodType, $periodKey, $planned, $userId);
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, array{target_task_id:int, amount:int}>>  $planned
     */
    private function write(
        int $storeId,
        string $periodType,
        string $periodKey,
        array $planned,
        ?int $userId,
    ): void {
        DB::transaction(function () use ($storeId, $periodType, $periodKey, $planned, $userId) {
            foreach ($planned as $sourceTaskId => $targets) {
                // Replace, never merge — same as a manual save.
                CleaningWeightAllocation::where('store_id', $storeId)
                    ->where('period_type', $periodType)
                    ->where('period_key', $periodKey)
                    ->where('source_task_id', $sourceTaskId)
                    ->delete();

                foreach ($targets as $target) {
                    CleaningWeightAllocation::create([
                        'store_id'       => $storeId,
                        'period_type'    => $periodType,
                        'period_key'     => $periodKey,
                        'source_task_id' => $sourceTaskId,
                        'target_task_id' => $target['target_task_id'],
                        'amount'         => $target['amount'],
                        'created_by'     => $userId,
                    ]);
                }
            }
        });
    }

    /**
     * `withTrashed()` — a soft-deleted task can still be the source of a split
     * on a past period, and "task #12" is a useless thing to show an auditor.
     */
    private function taskName(int $taskId): string
    {
        return CleaningTask::withTrashed()->whereKey($taskId)->value('name') ?? "task #{$taskId}";
    }

    private function hasExistingSplit(int $storeId, string $periodType, string $periodKey, int $sourceTaskId): bool
    {
        return CleaningWeightAllocation::where('store_id', $storeId)
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->where('source_task_id', $sourceTaskId)
            ->exists();
    }
}
