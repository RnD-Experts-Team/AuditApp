<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CleaningCompletion;
use App\Models\CleaningTask;
use App\Services\Cleaning\CleaningScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/cleaning/calendar/feed?stores=03795-00001[,…]&from=YYYY-MM-DD&to=YYYY-MM-DD[&group=day]
 *
 * `group=day` (year view, all-stores grid): instead of `events`, `days` — each
 * store's cleaning tasks and dough per day as done / pending / overdue / missed —
 * for up to 371 days. The cleaning items are counted without being listed.
 *
 * AuditApp's part of the unified calendar (calendar-feed/v1), cleaning AND dough:
 *   cleaning_day_summary  per store and day — the daily and hourly tasks, as counts
 *   cleaning_task         per weekly / monthly task, ONCE for its whole period
 *   dough_plan            per store and day — confirmed or not
 *   dough_judgement       per store and accounting week
 *
 * What is due, and each period, come from CleaningScheduleService — the same
 * rules the cleaning chart uses — and the status is CleaningDueService's: done
 * when the period has a completion, overdue once the period is over, else
 * pending. Everything is read in a fixed number of queries for all stores and
 * days at once (asking CleaningDueService day by day took ~3 queries per store
 * and day: ~5,600 for every store over a month).
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

    /** With group=day: a year — a 53-week fiscal year is 371 days. */
    private const MAX_DAYS_GROUPED = 371;

    /** The statuses a day counts; `info` and cancelled are not counted. */
    private const COUNTED = ['done' => 1, 'pending' => 1, 'overdue' => 1, 'missed' => 1, 'in_progress' => 1, 'scheduled' => 1];

    /** The worst status wins a day summary. */
    private const SEVERITY = ['done' => 0, 'pending' => 1, 'overdue' => 2];

    public function __construct(private readonly CleaningScheduleService $schedule)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stores' => ['required', 'string'],
            'from'   => ['required', 'date_format:Y-m-d'],
            'to'     => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group'  => ['nullable', 'in:day'],
        ]);
        $grouped = ($data['group'] ?? null) === 'day';
        $maxDays = $grouped ? self::MAX_DAYS_GROUPED : self::MAX_DAYS;

        $numbers = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['stores'])))));
        if ($numbers === [] || count($numbers) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > $maxDays) {
            throw ValidationException::withMessages(['to' => "Date range may not exceed {$maxDays} days."]);
        }

        // id => store number (the `store` column)
        $stores = DB::table('stores')->whereIn('store', $numbers)->pluck('store', 'id');
        $unknown = array_values(array_diff($numbers, $stores->values()->all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['stores' => 'Unknown store: ' . implode(', ', $unknown) . '.']);
        }

        $from = Carbon::parse($data['from'], 'UTC')->startOfDay();
        $to = Carbon::parse($data['to'], 'UTC')->startOfDay();

        if ($grouped) {
            $days = $this->days([...$this->cleaningCounts($stores, $from, $to), ...$this->dough($stores, $from, $to)]);

            return response()->json([
                'contract'     => 'calendar-feed/v1',
                'source'       => 'auditapp',
                'generated_at' => $this->utc(now()),
                'range'        => ['from' => $data['from'], 'to' => $data['to']],
                'stores'       => array_map(fn ($n) => ['number' => $n, 'timezone' => 'UTC'], $numbers),
                'group'        => 'day',
                'days'         => $days,
                'meta'         => ['count' => count($days), 'truncated' => false],
            ]);
        }

        $events = $this->cleaning($stores, $from, $to);
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

    /**
     * group=day: per store and day, how many things there are in each status.
     * A summary (or a count from cleaningCounts) adds its own counts; `info`,
     * cancelled and store-less events are not counted; empty days are left out.
     *
     * @return list<array{store: string, date: string, counts: array<string, int>}>
     */
    private function days(array $events): array
    {
        $days = [];
        foreach ($events as $e) {
            $add = isset($e['counts'])
                ? array_intersect_key($e['counts'], self::COUNTED)
                : (isset(self::COUNTED[$e['status']]) ? [$e['status'] => 1] : []);
            if ($e['store'] === null || array_sum($add) === 0) {
                continue;
            }
            $day = &$days[$e['date'] . '|' . $e['store']];
            $day ??= ['store' => $e['store'], 'date' => $e['date'], 'counts' => []];
            foreach (array_filter($add) as $status => $n) {
                $day['counts'][$status] = ($day['counts'][$status] ?? 0) + $n;
            }
            unset($day);
        }
        ksort($days);

        foreach ($days as &$day) {
            $day['counts']['total'] = array_sum($day['counts']);
        }
        unset($day);

        return array_values($days);
    }

    /**
     * group=day's cleaning: the counts per store and day straight away — a year
     * of every store is too many items to list one by one. The same rules as
     * dueItems() + cleaningForStore(): a daily or hourly task counts on its day,
     * a weekly or monthly one once per period, on the period's first day (or on
     * `from` when the period began earlier).
     *
     * @return list<array{store: string, date: string, status: string, counts: array<string, int>}>
     */
    private function cleaningCounts($stores, Carbon $from, Carbon $to): array
    {
        $storeIds = $stores->keys()->map(fn ($id) => (int) $id)->all();

        $tasks = CleaningTask::query()
            ->whereHas('stores', fn ($q) => $q->whereIn('stores.id', $storeIds))
            ->with(['stores' => fn ($q) => $q->whereIn('stores.id', $storeIds)->select('stores.id')])
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        $bounds = [];
        $period = function (CleaningTask $task, Carbon $day) use (&$bounds): array {
            return $bounds[$task->frequency . '|' . $day->toDateString()] ??= $this->schedule->periodBounds($task, $day);
        };

        // "task|store|period start" => true, streamed: a year of every store is a lot of rows.
        $earliest = $tasks->map(fn (CleaningTask $t) => $period($t, $from)[0])->min();
        $done = [];
        foreach (CleaningCompletion::query()->toBase()
            ->whereIn('store_id', $storeIds)
            ->whereIn('cleaning_task_id', $tasks->modelKeys())
            ->whereBetween('period_start', [$earliest->toDateString(), $to->toDateString()])
            ->select(['cleaning_task_id', 'store_id', 'period_start'])
            ->cursor() as $c) {
            $done[$c->cleaning_task_id . '|' . $c->store_id . '|' . substr((string) $c->period_start, 0, 10)] = true;
        }

        $now = now();
        $first = $from->toDateString();
        $counts = []; // store id => date => status => n

        foreach ($tasks as $task) {
            $periodic = in_array($task->frequency, ['weekly', 'monthly'], true);
            $seen = []; // a periodic task's periods already counted
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                if (! $this->schedule->isDueOnDate($task, $day)) {
                    continue;
                }
                [$start, $end] = $period($task, $day);
                $periodStart = $start->toDateString();
                if ($periodic && isset($seen[$periodStart])) {
                    continue;
                }
                $seen[$periodStart] = true;

                $date = $periodic ? max($periodStart, $first) : $day->toDateString();
                $over = $end->copy()->endOfDay()->lt($now);

                foreach ($task->stores as $store) {
                    $status = isset($done[$task->id . '|' . $store->id . '|' . $periodStart]) ? 'done' : ($over ? 'overdue' : 'pending');
                    $counts[$store->id][$date][$status] = ($counts[$store->id][$date][$status] ?? 0) + 1;
                }
            }
        }

        $out = [];
        foreach ($counts as $storeId => $byDate) {
            foreach ($byDate as $date => $c) {
                $out[] = ['store' => $stores[$storeId], 'date' => $date, 'status' => 'summary', 'counts' => $c];
            }
        }

        return $out;
    }

    /**
     * The due items of every store and day, as CleaningDueService would list them
     * one day at a time, in two reads: the stores' tasks, and their completions.
     *
     * @return array<string, array<string, list<array<string, mixed>>>>  store id => date => items
     */
    private function dueItems($stores, Carbon $from, Carbon $to): array
    {
        $storeIds = $stores->keys()->map(fn ($id) => (int) $id)->all();

        $tasks = CleaningTask::query()
            ->whereHas('stores', fn ($q) => $q->whereIn('stores.id', $storeIds))
            ->with(['stores' => fn ($q) => $q->whereIn('stores.id', $storeIds)->select('stores.id')])
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        // A period depends only on the frequency and the day, so it is worked out once per pair.
        $bounds = [];
        $period = function (CleaningTask $task, Carbon $day) use (&$bounds): array {
            return $bounds[$task->frequency . '|' . $day->toDateString()] ??= $this->schedule->periodBounds($task, $day);
        };

        // Completions from the start of the earliest period the range touches
        // (a monthly period can begin weeks before `from`). "task|store|period start" => completion.
        $earliest = $tasks->map(fn (CleaningTask $t) => $period($t, $from)[0])->min();
        $completions = CleaningCompletion::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('cleaning_task_id', $tasks->modelKeys())
            ->whereBetween('period_start', [$earliest->toDateString(), $to->toDateString()])
            ->orderBy('id')
            ->get(['id', 'cleaning_task_id', 'store_id', 'period_start', 'completed_at'])
            ->keyBy(fn ($c) => $c->cleaning_task_id . '|' . $c->store_id . '|' . $c->period_start->toDateString());

        $items = [];
        foreach ($tasks as $task) {
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                if (! $this->schedule->isDueOnDate($task, $day)) {
                    continue;
                }
                [$start, $end] = $period($task, $day);

                foreach ($task->stores as $store) {
                    $done = $completions->get($task->id . '|' . $store->id . '|' . $start->toDateString());

                    $items[$store->id][$day->toDateString()][] = [
                        'task_id'        => $task->id,
                        'label'          => $task->name,
                        'frequency'      => $task->frequency,
                        'weight'         => $task->weight,
                        'photo_required' => (bool) $task->photo_required,
                        'period'         => [$start->toDateString(), $end->toDateString()],
                        // CleaningDueService::status()
                        'status'         => $done ? 'done' : ($end->copy()->endOfDay()->lt(now()) ? 'overdue' : 'pending'),
                        'done_at'        => $done?->completed_at?->toIso8601String(),
                    ];
                }
            }
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function cleaning($stores, Carbon $from, Carbon $to): array
    {
        $due = $this->dueItems($stores, $from, $to);
        $events = [];

        foreach ($stores as $storeId => $store) {
            array_push($events, ...$this->cleaningForStore($store, $due[$storeId] ?? [], $from, $to));
        }

        return $events;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $dueByDate
     * @return array<int, array<string, mixed>>
     */
    private function cleaningForStore(string $store, array $dueByDate, Carbon $from, Carbon $to): array
    {
        $events = [];
        $periods = []; // weekly / monthly tasks, keyed "task|period start" so each period appears once

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            $counts = ['done' => 0, 'pending' => 0, 'overdue' => 0];

            foreach ($dueByDate[$date] ?? [] as $item) {
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
