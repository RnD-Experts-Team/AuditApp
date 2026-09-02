<?php

namespace App\Http\Controllers\Api\Cleaning;

use App\Http\Controllers\Controller;
use App\Models\CleaningSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Read and change the scoring settings at runtime.
 *
 * These live in a table rather than a config file precisely so the balance
 * between the two halves of the score can be retuned without a deploy. Writing
 * is Super-Admin only: changing `items_share` changes every store's number.
 */
class CleaningSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $v = CleaningSetting::values();

        return response()->json([
            'data' => [
                'score_formula'          => $v['score_formula'],
                'items_share'            => (int) $v['items_share'],
                'chart_share'            => (int) $v['chart_share'],
            ],
            'defaults' => CleaningSetting::DEFAULTS,
            'explain'  => [
                'score_formula' => "'average' = (items × items_share) + (chart × chart_share). "
                    . "'excel' = the previous formula, where the whole chart was one all-or-nothing point.",
                'shares'        => 'items_share + chart_share must equal 100.',
                'auto_fail'     => 'An auto_fail on an inspection item zeroes the ITEM half only — never the '
                    . 'final score. Cleaning tasks have no auto_fail state.',
                'frozen'        => 'Changing these never alters an evaluation that is already finalized — '
                    . 'those keep the scores they were finalized with.',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user && !$user->isSuperAdmin()) {
            abort(403, 'Only a super admin can change scoring settings.');
        }

        $data = $request->validate([
            'score_formula'          => ['sometimes', 'required', Rule::in(['average', 'excel'])],
            'items_share'            => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'chart_share'            => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
        ]);

        // Shares that do not total 100 would quietly scale every score, so reject
        // the pair rather than silently normalising and hiding a typo.
        if (array_key_exists('items_share', $data) || array_key_exists('chart_share', $data)) {
            $items = $data['items_share'] ?? CleaningSetting::getInt('items_share');
            $chart = $data['chart_share'] ?? CleaningSetting::getInt('chart_share');

            if ($items + $chart !== 100) {
                throw ValidationException::withMessages([
                    'items_share' => ["items_share + chart_share must equal 100 (got {$items} + {$chart})."],
                ]);
            }

            $data['items_share'] = $items;
            $data['chart_share'] = $chart;
        }

        foreach ($data as $key => $value) {
            CleaningSetting::put($key, $value, $user?->id);
        }

        return $this->index();
    }
}
