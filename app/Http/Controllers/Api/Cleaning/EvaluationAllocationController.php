<?php

namespace App\Http\Controllers\Api\Cleaning;

use App\Http\Controllers\Controller;
use App\Models\CleaningWeightAllocation;
use App\Models\Evaluation;
use App\Services\Cleaning\EvaluationAllocationService;
use App\Services\Cleaning\EvaluationService;
use App\Services\Cleaning\PeriodKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hands an absent task's weight to the tasks that ARE in play for a period.
 *
 * ── Worth knowing before using this ──
 * Splitting the weight PROPORTIONALLY changes nothing. The score is
 * earned/total; scaling every weight by the same factor cancels out:
 *
 *     (k·earned) / (k·total)  ==  earned / total
 *
 * So an "even split" button would compute for a second and move no number, and
 * doing nothing at all gives the same percentage as a perfect pro-rata split.
 * The feature is only meaningful when the auditor allocates UNEVENLY — putting
 * the absent weight on the tasks that matter most that week. That is exactly
 * what was asked for; it just means the default path can stay empty.
 */
class EvaluationAllocationController extends Controller
{
    public function __construct(
        private readonly EvaluationService $evaluations,
        private readonly PeriodKeyService $periods,
        private readonly EvaluationAllocationService $allocations,
    ) {
    }

    /**
     * Copy one store's whole split onto other stores, for the SAME period.
     *
     * The auditor builds the distribution once on store 1 and presses one
     * button for the rest. It is nearly free because tasks are shared between
     * stores and `weight` lives on the task row, so the amounts are already
     * correct for every store — see EvaluationAllocationService.
     *
     * `dry_run` returns exactly the same shape without writing anything, so the
     * UI can show "6 stores fine, 1 skipped — finalized" BEFORE committing. On
     * a bulk write across stores that preview is worth more than it costs.
     */
    public function copy(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge(PeriodKeyService::validationRules(), [
            'source_store_id'    => ['required', 'integer', 'exists:stores,id'],
            // Capped: without a limit, "select all stores" is one request that
            // rewrites every store's report in the chain.
            'target_store_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'target_store_ids.*' => ['integer', 'distinct', 'exists:stores,id'],
            'dry_run'            => ['nullable', 'boolean'],
        ]));

        $sourceStoreId = (int) $data['source_store_id'];
        $periodType    = $data['period_type'] ?? 'week';

        // Access is checked on the source AND every target. Silently dropping a
        // store the caller may not see would be worse than refusing outright —
        // he would believe the copy landed everywhere.
        $this->assertCanAccess($request, $sourceStoreId);

        $targets = collect($data['target_store_ids'])
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $sourceStoreId)   // copying onto itself is a no-op
            ->unique()
            ->values();

        foreach ($targets as $targetStoreId) {
            $this->assertCanAccess($request, $targetStoreId);
        }

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages([
                'target_store_ids' => ['Choose at least one store other than the source store.'],
            ]);
        }

        return response()->json($this->allocations->copy(
            $sourceStoreId,
            $targets->all(),
            $periodType,
            $data['period_key'],
            (bool) ($data['dry_run'] ?? false),
            Auth::id(),
        ));
    }

    /**
     * Clear saved splits across many stores at once — the undo for `copy()`.
     *
     * `destroy()` below removes one split from one store, which is right for the
     * ✕ Reset button but useless after a bulk copy: undoing a copy onto 8 stores
     * meant 24 calls from the client.
     *
     * Note it CLEARS rather than restores — see EvaluationAllocationService.
     */
    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge(PeriodKeyService::validationRules(), [
            'store_ids'        => ['required', 'array', 'min:1', 'max:50'],
            'store_ids.*'      => ['integer', 'distinct', 'exists:stores,id'],
            // Omit entirely to clear every split in the period — the "I copied
            // by mistake" case, which is what this endpoint exists for.
            'source_task_ids'   => ['nullable', 'array', 'max:100'],
            'source_task_ids.*' => ['integer', 'distinct', 'exists:cleaning_tasks,id'],
            'dry_run'           => ['nullable', 'boolean'],
        ]));

        $periodType = $data['period_type'] ?? 'week';
        $storeIds   = collect($data['store_ids'])->map(fn ($id) => (int) $id)->unique()->values();

        // Every store is checked: a silent skip would leave the auditor believing
        // the split was cleared everywhere.
        foreach ($storeIds as $storeId) {
            $this->assertCanAccess($request, $storeId);
        }

        return response()->json($this->allocations->remove(
            $storeIds->all(),
            $periodType,
            $data['period_key'],
            array_map('intval', $data['source_task_ids'] ?? []),
            (bool) ($data['dry_run'] ?? false),
        ));
    }

    /**
     * Current allocations plus the pool still waiting to be allocated.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge(PeriodKeyService::validationRules(), [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]));

        $this->assertCanAccess($request, (int) $data['store_id']);
        $periodType = $data['period_type'] ?? 'week';

        $row = $this->evaluations
            ->buildGrid($periodType, $data['period_key'], [(int) $data['store_id']])['rows']
            ->first();

        return response()->json([
            'period'       => $this->periods->describe($periodType, $data['period_key']),
            'absent_tasks' => $row['absent_tasks'] ?? [],
            'allocations'  => $row['allocations'] ?? [],
        ]);
    }

    /**
     * Replace the whole split for ONE source task, in one transaction.
     *
     * Partial splits are rejected: half-allocated weight is a silently wrong
     * report, which is worse than no allocation at all.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge(PeriodKeyService::validationRules(), [
            'store_id'                      => ['required', 'integer', 'exists:stores,id'],
            'source_task_id'                => ['required', 'integer', 'exists:cleaning_tasks,id'],
            'allocations'                   => ['required', 'array', 'min:1'],
            'allocations.*.target_task_id'  => ['required', 'integer', 'exists:cleaning_tasks,id'],
            'allocations.*.amount'          => ['required', 'integer', 'min:1'],
        ]));

        $storeId    = (int) $data['store_id'];
        $periodType = $data['period_type'] ?? 'week';
        $periodKey  = $data['period_key'];

        $this->assertCanAccess($request, $storeId);
        $this->assertNotFinalized($storeId, $periodType, $periodKey);

        $row    = $this->evaluations->buildGrid($periodType, $periodKey, [$storeId])['rows']->first();
        $source = collect($row['absent_tasks'] ?? [])->firstWhere('task_id', (int) $data['source_task_id']);

        if (!$source) {
            throw ValidationException::withMessages([
                'source_task_id' => ['That task is not absent this period, so it has no weight to give away.'],
            ]);
        }

        // Targets must be tasks actually in play, or the weight would vanish.
        $presentIds = collect($row['chart'] ?? [])->flatten(1)->pluck('task_id')->map(fn ($v) => (int) $v)->all();

        $total = 0;
        foreach ($data['allocations'] as $i => $allocation) {
            $targetId = (int) $allocation['target_task_id'];

            if ($targetId === (int) $data['source_task_id']) {
                throw ValidationException::withMessages([
                    "allocations.{$i}.target_task_id" => ['A task cannot receive its own weight.'],
                ]);
            }

            if (!in_array($targetId, $presentIds, true)) {
                throw ValidationException::withMessages([
                    "allocations.{$i}.target_task_id" => ['That task is not gradable this period, so it cannot receive weight.'],
                ]);
            }

            $total += (int) $allocation['amount'];
        }

        $sourceWeight = (int) $source['weight'];
        if ($total !== $sourceWeight) {
            throw ValidationException::withMessages([
                'allocations' => ["The split must add up to exactly {$sourceWeight} (got {$total})."],
            ]);
        }

        DB::transaction(function () use ($data, $storeId, $periodType, $periodKey) {
            CleaningWeightAllocation::where('store_id', $storeId)
                ->where('period_type', $periodType)
                ->where('period_key', $periodKey)
                ->where('source_task_id', $data['source_task_id'])
                ->delete();

            foreach ($data['allocations'] as $allocation) {
                CleaningWeightAllocation::create([
                    'store_id'       => $storeId,
                    'period_type'    => $periodType,
                    'period_key'     => $periodKey,
                    'source_task_id' => (int) $data['source_task_id'],
                    'target_task_id' => (int) $allocation['target_task_id'],
                    'amount'         => (int) $allocation['amount'],
                    'created_by'     => Auth::id(),
                ]);
            }
        });

        return response()->json([
            'data' => $this->evaluations->buildGrid($periodType, $periodKey, [$storeId])['rows']->first(),
        ]);
    }

    /**
     * Drop one source's split — back to plain renormalisation, which scores
     * identically to an even split anyway.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge(PeriodKeyService::validationRules(), [
            'store_id'       => ['required', 'integer', 'exists:stores,id'],
            'source_task_id' => ['required', 'integer', 'exists:cleaning_tasks,id'],
        ]));

        $storeId    = (int) $data['store_id'];
        $periodType = $data['period_type'] ?? 'week';

        $this->assertCanAccess($request, $storeId);
        $this->assertNotFinalized($storeId, $periodType, $data['period_key']);

        CleaningWeightAllocation::where('store_id', $storeId)
            ->where('period_type', $periodType)
            ->where('period_key', $data['period_key'])
            ->where('source_task_id', $data['source_task_id'])
            ->delete();

        return response()->json([
            'data' => $this->evaluations->buildGrid($periodType, $data['period_key'], [$storeId])['rows']->first(),
        ]);
    }

    // ── helpers ──

    private function assertNotFinalized(int $storeId, string $periodType, string $periodKey): void
    {
        $finalized = Evaluation::where('store_id', $storeId)
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->whereNotNull('finalized_at')
            ->exists();

        abort_if($finalized, 409, 'This evaluation is finalized.');
    }

    private function assertCanAccess(Request $request, int $storeId): void
    {
        $user = $request->user();
        if ($user && !$user->canAccessStoreId($storeId)) {
            abort(403, 'You cannot access this store.');
        }
    }
}
