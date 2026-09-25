<?php

namespace App\Http\Controllers\Api\DoughSauce;

use App\Http\Controllers\Controller;
use App\Http\Requests\DoughSauce\SaveJudgementRequest;
use App\Models\DsWeekJudgement;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

/**
 * The specialist's weekly judgement — stickers compliance and dough quality.
 *
 * Two booleans per store per week, and the only thing the specialist writes in
 * this whole module. They carry 40% of the score, and no system holds them:
 * LC_PIZZA_DATA does not know whether the stickers were applied, and the
 * inventory system does not know whether the dough was any good. Someone looked
 * and decided.
 *
 * Write only. Reading them is done by GET /dough-sauce/plans?week_start=, which
 * returns the whole week for every visible store — grid, report and judgements in
 * one response, instead of one request per store.
 */
class DsJudgementController extends Controller
{
    public function update(SaveJudgementRequest $request, string $store_id): JsonResponse
    {
        $storeId = Store::idFromNumber($store_id);
        abort_if($storeId === null, 404, 'Store not found.');

        $user = $request->user();

        

        // week_start is validated against the accounting calendar in
        // SaveJudgementRequest: the unique key is (store_id, week_start), so a
        // date one day off would not fail — it would quietly open a second
        // judgement for the same week.
        $payload = $request->validated();

        $judgement = DsWeekJudgement::updateOrCreate(
            [
                'store_id'   => $storeId,
                'week_start' => $payload['week_start'],
            ],
            [
                'stickers_compliance' => $payload['stickers_compliance'] ?? null,
                'dough_quality'       => $payload['dough_quality'] ?? null,
                'note'                => $payload['note'] ?? null,
                'judged_by'           => $user->id,
                'judged_at'           => now(),
            ],
        );

        return response()->json([
            'data' => [
                'store_id'            => $storeId,
                'week_start'          => $payload['week_start'],
                'stickers_compliance' => $judgement->stickers_compliance,
                'dough_quality'       => $judgement->dough_quality,
                'note'                => $judgement->note,
                'judged_by'           => ['id' => $user->id, 'name' => $user->name],
                'judged_at'           => $judgement->judged_at?->toIso8601String(),
            ],
        ]);
    }
}
