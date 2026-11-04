<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finalized cleaning evaluations on the local day they were finalized. The
 * scores are the ones frozen onto the evaluation at that moment; evaluations
 * finalized before scores were stored have none and are left out of averages.
 *
 * Timestamps are stored in UTC (config/app.php); the day is the New York one.
 * CONVERT_TZ needs MySQL's time zone tables loaded, or every date is NULL.
 */
class EvaluationsDataset extends ReportDataset
{
    public const KEY = 'audit.evaluations';

    private const REPORT_TIMEZONE = 'America/New_York';

    public function label(): string
    {
        return 'Cleaning evaluations';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store', groupable: true),
            new Column('date', 'Finalized date', 'date', $this->dateColumn(), groupable: true),
            new Column(
                'period_type',
                'Period type',
                'enum',
                'evaluations.period_type',
                options: [['value' => 'date', 'label' => 'Day'], ['value' => 'week', 'label' => 'Week']],
                groupable: true,
            ),
            new Column('score', 'Final score', 'number', 'evaluations.final_score', measure: true, aggregations: ['avg', 'min', 'max']),
            new Column('item_score', 'Item score', 'number', 'evaluations.item_score', measure: true, aggregations: ['avg', 'min', 'max']),
            new Column('chart_score', 'Chart score', 'number', 'evaluations.chart_score', measure: true, aggregations: ['avg', 'min', 'max']),
            new Column('count', 'Evaluations', 'integer', 'evaluations.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('evaluations')
            ->join('stores', 'stores.id', '=', 'evaluations.store_id')
            ->whereNotNull('evaluations.finalized_at');
    }

    public function storeColumn(): string
    {
        return 'evaluations.store_id';
    }

    public function dateColumn(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'DATE(evaluations.finalized_at)'
            : "DATE(CONVERT_TZ(evaluations.finalized_at, 'UTC', '".self::REPORT_TIMEZONE."'))";
    }

    /** stores.store holds the store code (03795-00044), which is what the wire carries. */
    public function storeKeys(array $codes): array
    {
        return DB::table('stores')->whereIn('store', $codes)->pluck('id')->all();
    }
}
