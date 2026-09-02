<?php

namespace App\Http\Controllers\Api\Cleaning;

use App\Http\Controllers\Controller;
use App\Models\CleaningTask;
use App\Models\Evaluation;
use App\Models\EvaluationChartVerdict;
use App\Models\EvaluationItemValue;
use App\Models\InspectionItem;
use App\Models\Store;
use App\Services\Cleaning\EvaluationService;
use App\Services\Cleaning\ManagerRecipientResolver;
use App\Services\Cleaning\PeriodKeyService;
use App\Services\Nats\EventFactory;
use App\Services\Nats\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EvaluationController extends Controller
{
    /**
     * Values a cell may hold. `empty` is not a state — it clears the cell.
     *
     * A cleaning TASK has no auto_fail: on a chart task it was arithmetically
     * identical to `fail`, so it was removed rather than kept as a state that
     * did nothing. Inspection items keep it — there it really does something.
     */
    private const CHART_VERDICTS = ['pass', 'fail', 'not_applicable', 'empty'];
    private const ITEM_VALUES    = ['pass', 'fail', 'auto_fail', 'not_applicable', 'empty'];

    public function __construct(
        private readonly EvaluationService $evaluations,
        private readonly EventFactory $events,
        private readonly OutboxService $outbox,
        private readonly ManagerRecipientResolver $recipients,
    ) {
    }

    /**
     * The full grid (all stores the caller may see).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(PeriodKeyService::validationRules());

        $grid = $this->evaluations->buildGrid(
            $data['period_type'] ?? 'week',
            $data['period_key'],
            $this->allowedStoreIds($request),
        );

        return response()->json($grid);
    }

    /**
     * Set one cell — an item value (Group A) or a chart verdict (Group B).
     *
     * Sending `empty` CLEARS the cell: the row, its note and its photo files are
     * deleted, so the cell becomes indistinguishable from one never touched.
     * That is the point — a half-undone mis-tap that keeps its failure note
     * attached is worse than no undo at all.
     */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge(PeriodKeyService::validationRules(), [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'kind'     => ['required', Rule::in(['item', 'chart'])],
            // item:
            'inspection_item_id' => ['required_if:kind,item', 'integer', 'exists:inspection_items,id'],
            'value'              => ['required_if:kind,item', Rule::in(self::ITEM_VALUES)],
            // chart:
            'cleaning_task_id' => ['required_if:kind,chart', 'integer', 'exists:cleaning_tasks,id'],
            'verdict'          => ['required_if:kind,chart', Rule::in(self::CHART_VERDICTS)],
            // optional on both kinds — the auditor's written notice + proof photos:
            'note'     => ['nullable', 'string'],
            'images'   => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'max:10240'],
        ]));

        $this->assertCanAccess($request, (int) $data['store_id']);
        $periodType = $data['period_type'] ?? 'week';
        $images = $request->file('images') ?? [];

        if ($locked = $this->finalizedResponse($data['store_id'], $periodType, $data['period_key'])) {
            return $locked;
        }

        DB::transaction(function () use ($data, $periodType, $images) {
            $evaluation = Evaluation::firstOrCreate(
                ['store_id' => $data['store_id'], 'period_type' => $periodType, 'period_key' => $data['period_key']],
                ['created_by' => Auth::id()],
            );

            $isItem = $data['kind'] === 'item';
            $state  = $isItem ? $data['value'] : $data['verdict'];

            $existing = $isItem
                ? EvaluationItemValue::where('evaluation_id', $evaluation->id)
                    ->where('inspection_item_id', $data['inspection_item_id'])->first()
                : EvaluationChartVerdict::where('evaluation_id', $evaluation->id)
                    ->where('cleaning_task_id', $data['cleaning_task_id'])->first();

            if ($state === 'empty') {
                $this->clearCell($existing);

                return;
            }

            if ($isItem) {
                $item = InspectionItem::findOrFail($data['inspection_item_id']);
                $cell = EvaluationItemValue::updateOrCreate(
                    ['evaluation_id' => $evaluation->id, 'inspection_item_id' => $item->id],
                    [
                        'value'  => $state,
                        // Snapshot the weight so editing the item later never
                        // re-scores an evaluation that already went out.
                        'weight' => (int) ($item->weight ?? 1),
                        'note'   => $data['note'] ?? null,
                    ],
                );
            } else {
                $task = CleaningTask::findOrFail($data['cleaning_task_id']);
                $cell = EvaluationChartVerdict::updateOrCreate(
                    ['evaluation_id' => $evaluation->id, 'cleaning_task_id' => $task->id],
                    [
                        'frequency' => $task->frequency,
                        'weight'    => (int) ($task->weight ?? 0),
                        'verdict'   => $state,
                        'note'      => $data['note'] ?? null,
                    ],
                );
            }

            foreach ($images as $image) {
                $cell->attachments()->create(['path' => $image->store('cleaning-evaluations', 'public')]);
            }
        });

        // Return the recalculated single-store row.
        $grid = $this->evaluations->buildGrid($periodType, $data['period_key'], [(int) $data['store_id']]);

        return response()->json(['data' => $grid['rows']->first()]);
    }

    /**
     * Finalize an evaluation → notify the store.
     *
     * Refuses while any cell is ungraded. The score itself no longer punishes
     * ungraded cells, so without this gate an auditor who graded only the passes
     * would hand every store 100%. The auditor's escape hatch is
     * `not_applicable`, which counts as complete but is not scored.
     */
    public function finalize(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge(PeriodKeyService::validationRules(), [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]));

        $this->assertCanAccess($request, (int) $data['store_id']);
        $periodType = $data['period_type'] ?? 'week';
        $storeId    = (int) $data['store_id'];

        $row = $this->evaluations->buildGrid($periodType, $data['period_key'], [$storeId])['rows']->first();

        if (!($row['is_complete'] ?? false)) {
            return response()->json([
                'message' => 'Cannot finalize an incomplete evaluation.',
                'missing' => $row['missing'] ?? [],
            ], 409);
        }

        $evaluation = Evaluation::where('store_id', $storeId)
            ->where('period_type', $periodType)
            ->where('period_key', $data['period_key'])
            ->first();

        if ($evaluation?->isFinalized()) {
            return response()->json(['message' => 'This evaluation is already finalized.'], 409);
        }

        // Freeze the numbers that were actually sent. Without this, retuning the
        // 50/50 shares later would rewrite reports stores have already received.
        $evaluation?->forceFill([
            'finalized_at'  => now(),
            'finalized_by'  => Auth::id(),
            'item_score'    => $row['item_score'] ?? null,
            'chart_score'   => $row['chart_score'] ?? null,
            'final_score'   => $row['final_score'] ?? null,
            'score_formula' => $row['score_formula'] ?? null,
        ])->save();

        $notifyUserIds = $this->recipients->forStores([$storeId]);
        $storeCode = Store::query()->whereKey($storeId)->value('store');

        // Ask NotificationsPizza to actually deliver — it resolves the
        // store managers itself from role + store (same pattern as
        // CleaningTaskController@store). Channels: 'web' = in-app.
        // The full evaluation context travels in `payload` so it lands in
        // NotificationsPizza's in_app_notifications.data for whoever reads it.
        // `stores` must be the shared store code — NotificationsPizza's
        // user_store_roles.store_id holds that code, not our internal id.
        $envelope = $this->events->make('notifications.v1.notification.role.send', [
            'channels' => ['web'],
            'roles'    => array_values((array) config('cleaning.manager_roles', ['Store Manager'])),
            'stores'   => [$storeCode],
            'payload'  => [
                'type'            => 'cleaning_evaluation_ready',
                'title'           => 'Store evaluation ready',
                'body'            => "Your store evaluation for {$periodType} {$data['period_key']} is ready — score {$row['final_score']}%.",
                'action_url'      => '/cleaning/evaluations?' . http_build_query(['store' => $storeCode, 'period_type' => $periodType, 'period_key' => $data['period_key']]),
                'store'           => $storeCode,
                'period_type'     => $periodType,
                'period_key'      => $data['period_key'],
                'item_score'      => $row['item_score'] ?? null,
                'chart_score'     => $row['chart_score'] ?? null,
                'final_score'     => $row['final_score'] ?? null,
                'commitment_pass' => $row['commitment_pass'] ?? null,
                'notify_user_ids' => $notifyUserIds,
            ],
        ]);
        $this->outbox->record('notifications.v1.notification.role.send', $envelope);

        $row['finalized_at'] = $evaluation?->finalized_at?->toIso8601String();

        return response()->json(['data' => $row]);
    }

    /**
     * Unlock a finalized evaluation. Super Admin only — the lock exists so a
     * store cannot be told one number and shown another.
     */
    public function reopen(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge(PeriodKeyService::validationRules(), [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]));

        $user = $request->user();
        if ($user && !$user->isSuperAdmin()) {
            abort(403, 'Only a super admin can reopen a finalized evaluation.');
        }

        $this->assertCanAccess($request, (int) $data['store_id']);
        $periodType = $data['period_type'] ?? 'week';

        $evaluation = Evaluation::where('store_id', $data['store_id'])
            ->where('period_type', $periodType)
            ->where('period_key', $data['period_key'])
            ->first();

        // Clear the frozen numbers as well — an editable evaluation must show
        // live scores, not the ones it was briefly finalized with.
        $evaluation?->forceFill([
            'finalized_at' => null, 'finalized_by' => null,
            'item_score' => null, 'chart_score' => null, 'final_score' => null, 'score_formula' => null,
        ])->save();

        $grid = $this->evaluations->buildGrid($periodType, $data['period_key'], [(int) $data['store_id']]);

        return response()->json(['data' => $grid['rows']->first()]);
    }

    // ── helpers ──

    /**
     * Delete a cell and everything hanging off it, files included. A cleared
     * cell must be indistinguishable from one that was never graded.
     */
    private function clearCell(EvaluationItemValue|EvaluationChartVerdict|null $cell): void
    {
        if (!$cell) {
            return;
        }

        foreach ($cell->attachments as $attachment) {
            Storage::disk('public')->delete($attachment->path);
        }

        $cell->attachments()->delete();
        $cell->delete();
    }

    private function finalizedResponse(int|string $storeId, string $periodType, string $periodKey): ?JsonResponse
    {
        $finalized = Evaluation::where('store_id', $storeId)
            ->where('period_type', $periodType)
            ->where('period_key', $periodKey)
            ->whereNotNull('finalized_at')
            ->exists();

        return $finalized
            ? response()->json(['message' => 'This evaluation is finalized.'], 409)
            : null;
    }

    /** @return int[] */
    private function allowedStoreIds(Request $request): array
    {
        $user = $request->user();
        return $user ? $user->allowedStoreIdsCached() : Store::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function assertCanAccess(Request $request, int $storeId): void
    {
        $user = $request->user();
        if ($user && !$user->canAccessStoreId($storeId)) {
            abort(403, 'You cannot access this store.');
        }
    }
}
