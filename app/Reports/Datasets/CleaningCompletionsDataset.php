<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cleaning chart tasks a store marked done, on the local day they were marked.
 * Only done rows exist (pending and overdue are computed on read), so
 * `completed` counts what was done; `on_time` is the part logged no later than
 * the last day of the period it covers, the app's own definition of not late
 * (CleaningCompletionLookup::anyLate). Notes and photos are never offered.
 *
 * Timestamps are stored in UTC (config/app.php); the day is the New York one.
 * CONVERT_TZ needs MySQL's time zone tables loaded, or every date is NULL.
 */
class CleaningCompletionsDataset extends ReportDataset
{
    public const KEY = 'audit.cleaning';

    private const REPORT_TIMEZONE = 'America/New_York';

    private const FREQUENCIES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'hourly' => 'Hourly'];

    public function label(): string
    {
        return 'Cleaning completions';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        $frequencies = array_map(
            fn ($value, $label) => ['value' => (string) $value, 'label' => $label],
            array_keys(self::FREQUENCIES),
            self::FREQUENCIES,
        );

        return [
            new Column('store', 'Store', 'string', 'stores.store', groupable: true),
            new Column('date', 'Completion date', 'date', $this->dateColumn(), groupable: true),
            new Column('task', 'Task', 'string', 'cleaning_tasks.name', groupable: true),
            new Column('frequency', 'Frequency', 'enum', 'cleaning_tasks.frequency', options: $frequencies, groupable: true),
            new Column('completed', 'Completed', 'integer', 'cleaning_completions.id', filterable: false, measure: true, aggregations: ['count']),
            new Column(
                'on_time',
                'Completed on time',
                'integer',
                'CASE WHEN DATE(cleaning_completions.completed_at) <= cleaning_completions.period_end THEN 1 ELSE 0 END',
                filterable: false,
                measure: true,
                aggregations: ['sum'],
            ),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('cleaning_completions')
            ->join('stores', 'stores.id', '=', 'cleaning_completions.store_id')
            ->join('cleaning_tasks', 'cleaning_tasks.id', '=', 'cleaning_completions.cleaning_task_id');
    }

    public function storeColumn(): string
    {
        return 'cleaning_completions.store_id';
    }

    public function dateColumn(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'DATE(cleaning_completions.completed_at)'
            : "DATE(CONVERT_TZ(cleaning_completions.completed_at, 'UTC', '".self::REPORT_TIMEZONE."'))";
    }

    /** stores.store holds the store code (03795-00044), which is what the wire carries. */
    public function storeKeys(array $codes): array
    {
        return DB::table('stores')->whereIn('store', $codes)->pluck('id')->all();
    }
}
