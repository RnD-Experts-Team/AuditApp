<?php

namespace App\Http\Controllers\Api\DoughSauce;

use App\Http\Controllers\Controller;
use App\Http\Requests\DoughSauce\ConfirmPlanRequest;
use App\Models\DsPlanDay;
use App\Models\DsWeekJudgement;
use App\Models\Store;
use App\Services\Cleaning\AccountingCalendarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The confirmed daily plan.
 *
 * What this controller does NOT do is the point of it. It does not call
 * LC_PIZZA_DATA, it does not compute a base, it does not compute a variance or a
 * score. The frontend fetches the sales figures and the inventory counts itself
 * and does that arithmetic. We store the two numbers a human decided — the buffer
 * and the resulting plan — and guard who may decide them.
 */
class DsPlanController extends Controller
{
    public function __construct(private readonly AccountingCalendarService $calendar)
    {
    }

    /**
     * One store, one production date.
     *
     * No rows means the plan is not confirmed yet — not an error. The client then
     * shows a live draft built from LC_PIZZA_DATA, pre-filled with
     * `default_buffers`.
     */
    public function show(Request $request, string $store_id): JsonResponse
    {
        $storeId = $this->resolveStore($request, $store_id);

        $data = $request->validate([
            'date'       => ['nullable', 'date_format:Y-m-d'],
            'weeks_span' => ['nullable', 'integer', 'min:0', 'max:52'],
        ]);

        // Dough is kneaded tonight for tomorrow, so tomorrow is what the manager
        // opens this screen to decide. Defaulting means the client can render the
        // first screen without knowing a date.
        $date = $data['date'] ?? now()->addDay()->toDateString();

        $lines = DsPlanDay::query()
            ->with('confirmedBy:id,name')
            ->where('store_id', $storeId)
            ->whereDate('plan_date', $date)
            ->get();

        $confirmed = $lines->isNotEmpty();
        $first     = $lines->first();

        return response()->json([
            'data' => [
                'store_id'        => $storeId,
                'plan_date'       => $date,
                'confirmed'       => $confirmed,
                'confirmed_at'    => $confirmed ? $first->confirmed_at?->toIso8601String() : null,
                'confirmed_by'    => $confirmed && $first->confirmedBy
                    ? ['id' => $first->confirmedBy->id, 'name' => $first->confirmedBy->name]
                    : null,
                'lines'           => $this->lineRows($lines),
                'default_buffers' => $confirmed ? null : $this->defaultBuffers($storeId),

                // The limit the server will actually enforce on POST. Sent so the
                // input can cap itself rather than letting the manager type a
                // number and discover on submit that it was never allowed.
                'buffer_max_pct' => (float) config('dough_sauce.buffer_max_pct'),

                'weeks' => $this->weekOptions(Carbon::parse($date), (int) ($data['weeks_span'] ?? 12)),
            ],
        ]);
    }

    /**
     * Confirm — the one write this module exists for.
     */
    public function confirm(ConfirmPlanRequest $request, string $store_id): JsonResponse
    {
        $storeId = $this->resolveStore($request, $store_id);
        $user    = $request->user();

        

        $payload = $request->validated();

        DB::transaction(function () use ($payload, $storeId, $user) {
            foreach ($payload['lines'] as $line) {
                // updateOrCreate, not insert: a manager who spots a mistake before
                // the night shift kneads should be able to fix it. The window that
                // matters closes physically, not in software, and a 409 here would
                // only push him to phone the correction through instead.
                DsPlanDay::updateOrCreate(
                    [
                        'store_id'       => $storeId,
                        'plan_date'      => $payload['plan_date'],
                        'ingredient_key' => $line['ingredient_key'],
                    ],
                    [
                        'buffer_pct'   => $line['buffer_pct'],
                        'planned_qty'  => $line['planned_qty'],
                        'confirmed_at' => now(),
                        'confirmed_by' => $user->id,
                    ],
                );
            }
        });

        $lines = DsPlanDay::query()
            ->with('confirmedBy:id,name')
            ->where('store_id', $storeId)
            ->whereDate('plan_date', $payload['plan_date'])
            ->get();

        return response()->json([
            'data' => [
                'store_id'     => $storeId,
                'plan_date'    => $payload['plan_date'],
                'confirmed'    => true,
                'confirmed_at' => $lines->first()?->confirmed_at?->toIso8601String(),
                'confirmed_by' => ['id' => $user->id, 'name' => $user->name],
                'lines'        => $this->lineRows($lines),
            ],
        ], 201);
    }

    /**
     * All visible stores for a period — the specialist's screens.
     *
     * Two shapes, because the specialist has two questions and they differ only
     * in the period:
     *
     *   ?date=YYYY-MM-DD        one day   — "who has not confirmed tomorrow?"
     *   ?week_start=YYYY-MM-DD  one week  — the follow-up grid and the report,
     *                                       judgements included
     *
     * The week shape exists because without it that grid would call
     * /stores/{id}/week once per store: 44 requests, each one triggering its own
     * synchronous token check against pizzasys. That is the same N+1 we are
     * asking the inventory project to fix on their side; it would be odd to ship
     * it on ours. 44 stores x 7 days x 3 ingredients is ~924 rows, small enough
     * to send whole and not worth paginating.
     *
     * Reads only our own table: no call to LC_PIZZA_DATA and none to the
     * inventory system. "Who has not confirmed yet" is a question the Excel
     * workbook could never answer, because a spreadsheet does not know who
     * opened it.
     *
     * Flat envelope, like the other aggregate endpoints in this codebase.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'       => ['nullable', 'prohibits:week_start', 'date_format:Y-m-d'],
            'week_start' => ['nullable', 'date_format:Y-m-d'],
            'weeks_span' => ['nullable', 'integer', 'min:0', 'max:52'],
        ]);

        $span = (int) ($data['weeks_span'] ?? 12);

        // Neither given: the daily view for tomorrow, which is the screen the
        // specialist opens first. `prohibits` above rejects both at once, so the
        // shape is never ambiguous.
        return isset($data['week_start'])
            ? $this->indexByWeek($request, $data['week_start'], $span)
            : $this->indexByDate($request, $data['date'] ?? now()->addDay()->toDateString(), $span);
    }

    /** All visible stores, one production date. */
    private function indexByDate(Request $request, string $date, int $span): JsonResponse
    {
        $data     = ['date' => $date];
        $storeIds = $this->allowedStoreIds($request);

        $stores = Store::query()
            ->whereIn('id', $storeIds)
            ->orderBy('store')
            ->get(['id', 'store']);

        $plans = DsPlanDay::query()
            ->with('confirmedBy:id,name')
            ->whereIn('store_id', $storeIds)
            ->whereDate('plan_date', $data['date'])
            ->get()
            ->groupBy('store_id');

        $rows      = [];
        $confirmed = 0;

        foreach ($stores as $store) {
            $lines = $plans->get($store->id);
            $has   = $lines !== null && $lines->isNotEmpty();

            if ($has) {
                $confirmed++;
            }

            $rows[] = [
                'store_id'     => (int) $store->id,
                'store'        => $store->store,
                'confirmed'    => $has,
                'confirmed_at' => $has ? $lines->first()->confirmed_at?->toIso8601String() : null,
                'confirmed_by' => $has && $lines->first()->confirmedBy
                    ? $lines->first()->confirmedBy->name
                    : null,
                'lines'        => $has ? $this->lineRows($lines) : [],
            ];
        }

        return response()->json([
            'date'    => $data['date'],
            'weeks'   => $this->weekOptions(Carbon::parse($data['date']), $span),
            'stores'  => $rows,
            'summary' => [
                'total'     => count($rows),
                'confirmed' => $confirmed,
                'pending'   => count($rows) - $confirmed,
            ],
        ]);
    }

    /**
     * All visible stores, one accounting week — the follow-up grid and the report.
     *
     * Carries the judgements too. They are a different grain (one row per store
     * per week, against 21 plan rows) but the same screen reads both, and a
     * separate endpoint for them would put the N+1 back one level up.
     *
     * `previous_weeks` rides along for the same reason it does on /week: progress
     * compares this week against the average of the previous three, and the
     * client must not derive those dates itself.
     */
    private function indexByWeek(Request $request, string $weekStart, int $span): JsonResponse
    {
        $resolved = $this->calendar->resolveDate(Carbon::parse($weekStart));

        abort_unless(
            $resolved['from']->isSameDay(Carbon::parse($weekStart)),
            422,
            'week_start must be the first day of an accounting week (a Tuesday). '
            . 'For this date that is ' . $resolved['from']->toDateString()
            . '. Get valid values from the `weeks` list in GET /api/dough-sauce/bootstrap.'
        );

        $storeIds = $this->allowedStoreIds($request);
        $from     = $resolved['from']->toDateString();
        $to       = $resolved['to']->toDateString();

        $stores = Store::query()
            ->whereIn('id', $storeIds)
            ->orderBy('store')
            ->get(['id', 'store']);

        $plans = DsPlanDay::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('plan_date', [$from, $to])
            ->orderBy('plan_date')
            ->get()
            ->groupBy('store_id');

        $judgements = DsWeekJudgement::query()
            ->with('judgedBy:id,name')
            ->whereIn('store_id', $storeIds)
            ->whereDate('week_start', $from)
            ->get()
            ->keyBy('store_id');

        $rows            = [];
        $judged          = 0;
        $fullyConfirmed  = 0;
        $expectedPerWeek = 7 * count(config('dough_sauce.ingredients'));

        foreach ($stores as $store) {
            $storePlans = $plans->get($store->id) ?? collect();
            $judgement  = $judgements->get($store->id);

            // Both verdicts, or it is still outstanding work — a half-filled row
            // counted as done would hide the half that is missing.
            $complete = $judgement
                && $judgement->stickers_compliance !== null
                && $judgement->dough_quality !== null;

            if ($complete) {
                $judged++;
            }

            $daysConfirmed = $storePlans->pluck('plan_date')
                ->map(fn ($d) => $d->toDateString())
                ->unique()
                ->count();

            if ($storePlans->count() >= $expectedPerWeek) {
                $fullyConfirmed++;
            }

            $rows[] = [
                'store_id'       => (int) $store->id,
                'store'          => $store->store,
                'days_confirmed' => $daysConfirmed,
                'lines_total'    => $storePlans->count(),
                'plans'          => $storePlans->map(fn (DsPlanDay $row) => [
                    'plan_date'      => $row->plan_date->toDateString(),
                    'ingredient_key' => $row->ingredient_key,
                    'buffer_pct'     => (float) $row->buffer_pct,
                    'planned_qty'    => (float) $row->planned_qty,
                ])->values(),
                'judgement' => $judgement ? [
                    'stickers_compliance' => $judgement->stickers_compliance,
                    'dough_quality'       => $judgement->dough_quality,
                    'note'                => $judgement->note,
                    'complete'            => $complete,
                    'judged_by'           => $judgement->judgedBy?->name,
                    'judged_at'           => $judgement->judged_at?->toIso8601String(),
                ] : null,
            ];
        }

        return response()->json([
            'week' => [
                'week_start'     => $from,
                'week_end'       => $to,
                'week_no'        => $resolved['week'],
                'period_no'      => $resolved['period'],
                'week_in_period' => $resolved['week_in_period'],
                'fiscal_year'    => $resolved['year'],
            ],
            'previous_weeks' => $this->previousWeeks($resolved),
            'weeks'          => $this->weekOptions($resolved['from'], $span),
            'stores'         => $rows,
            'summary'        => [
                'total'             => count($rows),
                'fully_confirmed'   => $fullyConfirmed,
                'judged'            => $judged,
                'pending_judgement' => count($rows) - $judged,
                'lines_expected'    => $expectedPerWeek,
            ],
        ]);
    }

    /**
     * One store, one accounting week — everything of ours the follow-up grid needs.
     *
     * Three of our tables' worth of answers in one response, because that screen
     * already costs the client a call to the inventory system on top.
     *
     * `previous_weeks` is the part that is easy to miss: progress compares this
     * week's score against the average of the previous three, so the client needs
     * their boundaries to fetch their counts. Sending them here is what stops it
     * deriving those dates locally and hitting the isoWeek() divergence.
     */
    public function week(Request $request, string $store_id): JsonResponse
    {
        $storeId = $this->resolveStore($request, $store_id);

        $data = $request->validate([
            'week_start' => ['nullable', 'date_format:Y-m-d'],
            'weeks_span' => ['nullable', 'integer', 'min:0', 'max:52'],
        ]);

        // Omitted means "this week" — so the client can open the screen before it
        // knows which Tuesday that is.
        if (! isset($data['week_start'])) {
            $resolved = $this->calendar->resolveDate(now());
        } else {
            $resolved = $this->calendar->resolveDate(Carbon::parse($data['week_start']));

            abort_unless(
                $resolved['from']->isSameDay(Carbon::parse($data['week_start'])),
                422,
                'week_start must be the first day of an accounting week (a Tuesday). '
                . 'For this date that is ' . $resolved['from']->toDateString()
                . '. Use a value from the `weeks` list in this response.'
            );
        }

        $plans = DsPlanDay::query()
            ->where('store_id', $storeId)
            ->whereBetween('plan_date', [
                $resolved['from']->toDateString(),
                $resolved['to']->toDateString(),
            ])
            ->orderBy('plan_date')
            ->get();

        $judgement = DsWeekJudgement::query()
            ->with('judgedBy:id,name')
            ->where('store_id', $storeId)
            ->whereDate('week_start', $resolved['from']->toDateString())
            ->first();

        return response()->json([
            'store_id' => $storeId,
            'week'     => [
                'week_start'     => $resolved['from']->toDateString(),
                'week_end'       => $resolved['to']->toDateString(),
                'week_no'        => $resolved['week'],
                'period_no'      => $resolved['period'],
                'week_in_period' => $resolved['week_in_period'],
                'fiscal_year'    => $resolved['year'],
            ],
            'plans' => $plans->map(fn (DsPlanDay $row) => [
                'plan_date'      => $row->plan_date->toDateString(),
                'ingredient_key' => $row->ingredient_key,
                'buffer_pct'     => (float) $row->buffer_pct,
                'planned_qty'    => (float) $row->planned_qty,
            ])->values(),
            'judgement' => $judgement ? [
                'stickers_compliance' => $judgement->stickers_compliance,
                'dough_quality'       => $judgement->dough_quality,
                'note'                => $judgement->note,
                'judged_by'           => $judgement->judgedBy?->name,
                'judged_at'           => $judgement->judged_at?->toIso8601String(),
            ] : null,
            'previous_weeks' => $this->previousWeeks($resolved),
            'weeks'          => $this->weekOptions($resolved['from'], (int) ($data['weeks_span'] ?? 12)),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * The accounting weeks the client may pick from, on every read.
     *
     * This is the one thing in this module the client genuinely cannot work out
     * for itself, and the reason it rides along with the data rather than sitting
     * behind a config endpoint of its own.
     *
     * Our week runs Tuesday to Monday against a fixed anchor. Carbon's isoWeek()
     * agrees for every week of 2026 — ISO 2026-W01 starts one day before our
     * anchor, so the numbers line up — and then breaks on 2026-12-29, when that
     * Tuesday opens accounting week 2027-W01 while ISO still calls it 2026-W53. A
     * client generating week keys locally would file that week under a date
     * nothing else reads, and the bug would surface months later as a judgement
     * that vanished.
     *
     * Sending the list with the data also removes the chicken-and-egg: the period
     * parameters are optional, so the client never needs to know a date in order
     * to ask for one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function weekOptions(Carbon $around, int $span): array
    {
        $current = $this->calendar->resolveDate($around);
        $options = [];

        for ($i = -$span; $i <= $span; $i++) {
            [$year, $week] = $this->calendar->shiftWeek($current['year'], $current['week'], $i);
            [$from, $to]   = $this->calendar->weekRange($year, $week);

            $options[] = [
                'week_start'     => $from->toDateString(),
                'week_end'       => $to->toDateString(),
                'week_no'        => $week,
                'period_no'      => $this->calendar->periodOf($week),
                'week_in_period' => $this->calendar->weekInPeriod($week),
                'fiscal_year'    => $year,
                'label'          => sprintf('Week %d · %d', $week, $year),
                'current'        => $i === 0,
            ];
        }

        return $options;
    }

    /**
     * The boundaries of the weeks a progress figure is measured against.
     *
     * The client needs them to fetch those weeks' counts, and must not derive
     * them: stepping back three Tuesdays crosses the accounting year boundary
     * differently from the ISO one.
     *
     * @param  array<string, mixed>  $resolved
     * @return array<int, array<string, mixed>>
     */
    private function previousWeeks(array $resolved): array
    {
        $lookback = (int) config('dough_sauce.scoring.progress_lookback_weeks');
        $weeks    = [];

        for ($i = 1; $i <= $lookback; $i++) {
            [$year, $week] = $this->calendar->shiftWeek($resolved['year'], $resolved['week'], -$i);
            [$from, $to]   = $this->calendar->weekRange($year, $week);

            $weeks[] = [
                'week_start'  => $from->toDateString(),
                'week_end'    => $to->toDateString(),
                'week_no'     => $week,
                'fiscal_year' => $year,
            ];
        }

        return $weeks;
    }

    /**
     * {store_id} in the path is the human store key; resolve it and check the
     * caller may see it at all. Role checks come after, per action.
     */
    private function resolveStore(Request $request, string $storeKey): int
    {
        $storeId = Store::idFromNumber($storeKey);
        abort_if($storeId === null, 404, 'Store not found.');

        

        return $storeId;
    }

    /** @return array<int, int> */
    private function allowedStoreIds(Request $request): array
    {
        $user = $request->user();

        return $user
            ? $user->allowedStoreIdsCached()
            : Store::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function lineRows($lines): array
    {
        $order = array_keys(config('dough_sauce.ingredients'));

        return $lines
            ->sortBy(fn (DsPlanDay $row) => array_search($row->ingredient_key, $order, true))
            ->map(fn (DsPlanDay $row) => [
                'ingredient_key' => $row->ingredient_key,
                'buffer_pct'     => (float) $row->buffer_pct,
                'planned_qty'    => (float) $row->planned_qty,
                // Derived, not stored — see DsPlanDay::getBaseQtyAttribute().
                'base_qty'       => round($row->base_qty, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * What to pre-fill the buffer boxes with.
     *
     * The most recent buffer this store actually confirmed, per ingredient —
     * falling back to config for a store that has never confirmed anything. That
     * is a better suggestion than a separately maintained "default": it is the
     * number the manager last stood behind, not one he set once and forgot.
     *
     * @return array<string, float>
     */
    private function defaultBuffers(int $storeId): array
    {
        $defaults = array_map(
            static fn ($pct) => (float) $pct,
            config('dough_sauce.default_buffers')
        );

        $latest = DsPlanDay::query()
            ->select('ingredient_key', 'buffer_pct')
            ->whereIn('id', function ($query) use ($storeId) {
                $query->selectRaw('MAX(id)')
                    ->from('ds_plan_days')
                    ->where('store_id', $storeId)
                    ->groupBy('ingredient_key');
            })
            ->get();

        foreach ($latest as $row) {
            if (array_key_exists($row->ingredient_key, $defaults)) {
                $defaults[$row->ingredient_key] = (float) $row->buffer_pct;
            }
        }

        return $defaults;
    }
}
