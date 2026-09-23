<?php

namespace App\Http\Controllers\Api\Cleaning;

use App\Http\Controllers\Controller;
use App\Services\Cleaning\PeriodKeyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The selectable periods, with their real date ranges.
 *
 * The client used to generate `period_key` itself from the ISO week number. For
 * every week of 2026 that produces the right answer — ISO 2026-W01 starts
 * 2025-12-29, one day before our anchor, so the numbers line up — but the
 * alignment is a coincidence and it breaks on 2026-12-29: that Tuesday opens
 * accounting week 2027-W01 while ISO calls it 2026-W53. A client generating keys
 * locally would file that week under an unreachable key.
 *
 * So the server owns the list. Build the dropdown from `options`, default to
 * `current`, and never compute a week key on the client again.
 */
class CleaningPeriodController extends Controller
{
    public function __construct(private readonly PeriodKeyService $periods)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'   => ['nullable', Rule::in(['date', 'week'])],
            'around' => ['nullable', 'date'],
            'span'   => ['nullable', 'integer', 'min:0', 'max:26'],
        ]);

        $type   = $data['type'] ?? 'week';
        $around = isset($data['around']) ? Carbon::parse($data['around']) : now();
        $span   = (int) ($data['span'] ?? ($type === 'week' ? 4 : 7));

        return response()->json($this->periods->options($type, $around, $span));
    }
}
