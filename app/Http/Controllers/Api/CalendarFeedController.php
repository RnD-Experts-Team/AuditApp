<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cleaning\CleaningDueService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/cleaning/calendar/feed?stores=03795-00001[,…]&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * AuditApp's part of the unified calendar (calendar-feed/v1), cleaning AND dough:
 *   cleaning_day_summary  per store and day — the daily and hourly tasks, as counts
 *   cleaning_task         per weekly / monthly task, ONCE for its whole period
 *   dough_plan            per store and day — confirmed or not
 *   dough_judgement       per store and accounting week
 *
 * What is due and its status come from CleaningDueService, unchanged — the same
 * rules the cleaning chart uses, so the calendar can never disagree with it.
 *
 * Who may see which stores is pizzasys's decision: the auth middleware forwards
 * the query string and the rule for this route reads `stores` from it (policy
 * "all"). We only refuse numbers we do not know, because pizzasys silently drops
 * those instead of refusing them.
 *
 * Times are UTC. Never sent: photos, notes, dough quantities, employee lists.
 */
class CalendarFeedController extends Controller
{
    private const MAX_STORES = 50;

    private const MAX_DAYS = 62;

    /** The worst status wins a day summary. */
    private const SEVERITY = ['done' => 0, 'pending' => 1, 'overdue' => 2];

    public function __construct(private readonly CleaningDueService $due)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stores' => ['required', 'string'],
            'from'   => ['required', 'date_format:Y-m-d'],
            'to'     => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $numbers = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['stores'])))));
        if ($numbers === [] || count($numbers) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Date range may not exceed ' . self::MAX_DAYS . ' days.']);
        }

        // id => store number (the `store` column)
        $stores = DB::table('stores')->whereIn('store', $numbers)->pluck('store', 'id');
        $unknown = array_values(array_diff($numbers, $stores->values()->all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['stores' => 'Unknown store: ' . implode(', ', $unknown) . '.']);
        }

        $from = Carbon::parse($data['from'], 'UTC')->startOfDay();
        $to = Carbon::parse($data['to'], 'UTC')->startOfDay();

        $events = [];
        foreach ($stores as $storeId => $number) {
            array_push($events, ...$this->cleaning((int) $storeId, $number, $from, $to));
        }
        array_push($events, ...$this->dough($stores, $from, $to));

        usort($events, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        return response()->json([
            'contract'     => 'calendar-feed/v1',
            'source'       => 'auditapp',
            'generated_at' => $this->utc(now()),
            'range'        => ['from' => $data['from'], 'to' => $data['to']],
            'stores'       => array_map(fn ($n) => ['number' => $n, 'timezone' => 'UTC'], $numbers),
            'events'       => $events,
            'meta'         => ['count' => count($events), 'truncated' => false],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function cleaning(int $storeId, string $store, Carbon $from, Carbon $to): array
    {
        $events = [];
        $periods = []; // weekly / monthly tasks, keyed "task|period start" so each period appears once

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            $counts = ['done' => 0, 'pending' => 0, 'overdue' => 0];

            foreach ($this->due->dueForStoreOnDate($storeId, $day) as $item) {
                if (in_array($item['frequency'], ['weekly', 'monthly'], true)) {
                    $periods[$item['task_id'] . '|' . $item['period'][0]] ??= $item;
                    continue;
                }
                $counts[$item['status']]++;
            }

            $total = array_sum($counts);
            if ($total === 0) {
                continue;
            }

            $status = collect($counts)->filter()->keys()->sortByDesc(fn ($s) => self::SEVERITY[$s])->first();

            $events[] = $this->event(
                id: "auditapp:cleaning_day_summary:{$store}:{$date}",
                type: 'cleaning_day_summary',
                layer: 'cleaning',
                store: $store,
                title: "Cleaning {$counts['done']}/{$total}" . ($counts['overdue'] ? " · {$counts['overdue']} overdue" : ''),
                date: $date,
                dateEnd: null,
                status: $status,
                link: '/cleaning/tasks?' . http_build_query(['store' => $store, 'date' => $date]),
                extra: [],
                counts: $counts + ['total' => $total],
            );
        }

        foreach ($periods as $item) {
            $events[] = $this->event(
                id: "auditapp:cleaning_task:{$item['task_id']}:{$store}:{$item['period'][0]}",
                type: 'cleaning_task',
                layer: 'cleaning',
                store: $store,
                title: $item['label'],
                date: $item['period'][0],
                dateEnd: $item['period'][1],
                status: $item['status'],
                link: "/cleaning/tasks/{$item['task_id']}?" . http_build_query(['store' => $store]),
                extra: [
                    'task_id'        => (int) $item['task_id'],
                    'frequency'      => $item['frequency'],
                    'weight'         => $item['weight'],
                    'photo_required' => (bool) $item['photo_required'],
                    'done_at'        => $this->utc($item['done_at']),
                ],
            );
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function dough($stores, Carbon $from, Carbon $to): array
    {
        // store id => [confirmed date => confirmed_at] — one row per day is enough
        // (a plan is one row per ingredient).
        $confirmed = DB::table('ds_plan_days')
            ->whereIn('store_id', $stores->keys())
            ->whereBetween('plan_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('store_id, plan_date, MIN(confirmed_at) as confirmed_at')
            ->groupBy('store_id', 'plan_date')
            ->get()
            ->groupBy('store_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [Carbon::parse($r->plan_date)->toDateString() => $r->confirmed_at]));

        $today = Carbon::now('UTC')->toDateString();
        $events = [];

        foreach ($stores as $storeId => $store) {
            $byDate = $confirmed->get($storeId, collect());

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $date = $day->toDateString();
                $at = $byDate->get($date);
                $status = $at ? 'done' : ($date < $today ? 'missed' : 'pending');

                $events[] = $this->event(
                    id: "auditapp:dough_plan:{$store}:{$date}",
                    type: 'dough_plan',
                    layer: 'dough',
                    store: $store,
                    title: $at ? 'Dough confirmed' : 'Dough not confirmed',
                    date: $date,
                    dateEnd: null,
                    status: $status,
                    link: '/dough-sauce?' . http_build_query(['store' => $store, 'date' => $date]),
                    extra: ['confirmed_at' => $this->utc($at)],
                );
            }
        }

        $judgements = DB::table('ds_week_judgements')
            ->whereIn('store_id', $stores->keys())
            ->whereBetween('week_start', [$from->toDateString(), $to->toDateString()])
            ->get(['store_id', 'week_start', 'stickers_compliance', 'dough_quality', 'judged_at', 'updated_at']);

        foreach ($judgements as $j) {
            $store = $stores[$j->store_id];
            $week = Carbon::parse($j->week_start)->toDateString();

            $events[] = $this->event(
                id: "auditapp:dough_judgement:{$store}:{$week}",
                type: 'dough_judgement',
                layer: 'dough',
                store: $store,
                title: 'Dough week judgement',
                date: $week,
                dateEnd: null,
                status: 'done',
                link: '/dough-sauce?' . http_build_query(['store' => $store, 'week_start' => $week]),
                extra: [
                    'stickers_compliance' => $j->stickers_compliance,
                    'dough_quality'       => $j->dough_quality,
                    'judged_at'           => $this->utc($j->judged_at),
                ],
                updatedAt: $j->updated_at,
            );
        }

        return $events;
    }

    /** @return array<string, mixed> */
    private function event(
        string $id,
        string $type,
        string $layer,
        string $store,
        string $title,
        string $date,
        ?string $dateEnd,
        string $status,
        string $link,
        array $extra,
        ?array $counts = null,
        $updatedAt = null,
    ): array {
        $event = [
            'id'         => $id,
            'type'       => $type,
            'layer'      => $layer,
            'store'      => $store,
            'title'      => mb_substr($title, 0, 120),
            'all_day'    => true,
            'date'       => $date,
            'date_end'   => $dateEnd,
            'start'      => null,
            'end'        => null,
            'status'     => $status,
            'people'     => [],
            'link'       => $link,
            'updated_at' => $this->utc($updatedAt ?? now()),
        ];

        // Left out when empty: PHP would send [] where the contract expects an object.
        if ($extra !== []) {
            $event['extra'] = $extra;
        }

        if ($counts !== null) {
            $event['counts'] = $counts;
        }

        return $event;
    }

    private function utc($at): ?string
    {
        return $at ? Carbon::parse($at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
