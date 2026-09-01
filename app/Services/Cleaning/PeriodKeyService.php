<?php

namespace App\Services\Cleaning;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The ONE place that maps a `period_key` string onto real dates, and back.
 *
 * Formats:
 *   period_type=date  ->  "2026-08-20"      a single day
 *   period_type=week  ->  "2026-W34"        an ACCOUNTING week (Tue -> Mon)
 *
 * The week number is the accounting week, not the ISO week. They coincide for
 * every week of 2026 but diverge from 2026-12-29 onward — see
 * AccountingCalendarService.
 */
class PeriodKeyService
{
    public const TYPE_DATE = 'date';
    public const TYPE_WEEK = 'week';

    public function __construct(private readonly AccountingCalendarService $calendar)
    {
    }

    /**
     * @return array{0: Carbon, 1: Carbon}  [from, to] inclusive
     */
    public function range(string $periodType, string $periodKey): array
    {
        if ($periodType === self::TYPE_DATE) {
            $d = $this->parseDate($periodKey);

            return [$d, $d->copy()];
        }

        [$year, $week] = $this->parseWeek($periodKey);

        try {
            return $this->calendar->weekRange($year, $week);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['period_key' => [$e->getMessage()]]);
        }
    }

    public function keyForDate(string $periodType, CarbonInterface $date): string
    {
        if ($periodType === self::TYPE_DATE) {
            return $date->copy()->startOfDay()->toDateString();
        }

        $r = $this->calendar->resolveDate($date);

        return sprintf('%d-W%02d', $r['year'], $r['week']);
    }

    /**
     * The `period` block returned alongside every grid/report response, so the
     * client never has to work out which dates a key covers.
     *
     * @return array<string, mixed>
     */
    public function describe(string $periodType, string $periodKey): array
    {
        [$from, $to] = $this->range($periodType, $periodKey);

        if ($periodType === self::TYPE_DATE) {
            return [
                'type'  => self::TYPE_DATE,
                'key'   => $periodKey,
                'label' => $from->format('D M j, Y'),
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
            ];
        }

        [$year, $week] = $this->parseWeek($periodKey);

        return [
            'type'           => self::TYPE_WEEK,
            'key'            => sprintf('%d-W%02d', $year, $week),
            'year'           => $year,
            'week'           => $week,
            'period'         => $this->calendar->periodOf($week),
            'week_in_period' => $this->calendar->weekInPeriod($week),
            'label'          => $this->label($year, $week, $from, $to),
            'from'           => $from->toDateString(),
            'to'             => $to->toDateString(),
        ];
    }

    /**
     * Selectable periods for the client's dropdown: `$span` either side of the
     * period containing `$around`, oldest first.
     *
     * @return array{current: string, options: array<int, array<string, mixed>>}
     */
    public function options(string $periodType, CarbonInterface $around, int $span = 4): array
    {
        $current = $this->keyForDate($periodType, $around);
        $options = [];

        if ($periodType === self::TYPE_DATE) {
            for ($i = -$span; $i <= $span; $i++) {
                $d = $around->copy()->startOfDay()->addDays($i);
                $options[] = $this->describe(self::TYPE_DATE, $d->toDateString());
            }

            return ['current' => $current, 'options' => $options];
        }

        $r = $this->calendar->resolveDate($around);

        for ($i = -$span; $i <= $span; $i++) {
            [$y, $w] = $this->calendar->shiftWeek($r['year'], $r['week'], $i);
            $options[] = $this->describe(self::TYPE_WEEK, sprintf('%d-W%02d', $y, $w));
        }

        return ['current' => $current, 'options' => $options];
    }

    /**
     * Validation rules for the two request fields. Replaces the old
     * `['required','string','max:20']`, which accepted anything.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function validationRules(bool $required = true): array
    {
        return [
            'period_type' => ['nullable', 'in:date,week'],
            'period_key'  => [
                $required ? 'required' : 'nullable',
                'string',
                'max:20',
                function (string $attribute, mixed $value, callable $fail) {
                    $type = request()->input('period_type', self::TYPE_WEEK);

                    if ($type === self::TYPE_DATE) {
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                            $fail('The period key must look like 2026-08-20 when period_type is date.');
                        }

                        return;
                    }

                    if (!preg_match('/^\d{4}-W\d{2}$/', (string) $value)) {
                        $fail('The period key must look like 2026-W34 when period_type is week.');
                    }
                },
            ],
        ];
    }

    // ── internals ──

    /**
     * @return array{0:int, 1:int}
     */
    private function parseWeek(string $key): array
    {
        if (!preg_match('/^(\d{4})-W(\d{1,2})$/', trim($key), $m)) {
            throw ValidationException::withMessages([
                'period_key' => ['The period key must look like 2026-W34 when period_type is week.'],
            ]);
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function parseDate(string $key): Carbon
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($key))) {
            throw ValidationException::withMessages([
                'period_key' => ['The period key must look like 2026-08-20 when period_type is date.'],
            ]);
        }

        return Carbon::parse(trim($key))->startOfDay();
    }

    private function label(int $year, int $week, Carbon $from, Carbon $to): string
    {
        $range = $from->format('M j') . '–' . ($from->month === $to->month ? $to->format('j') : $to->format('M j'));

        return "Week {$week} · {$range}";
    }
}
