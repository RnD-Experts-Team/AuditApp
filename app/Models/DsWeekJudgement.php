<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The specialist's weekly verdict on one store — 40% of that week's score.
 *
 * `week_start` is always a Tuesday, produced by AccountingCalendarService.
 */
class DsWeekJudgement extends Model
{
    protected $fillable = [
        'store_id',
        'week_start',
        'stickers_compliance',
        'dough_quality',
        'note',
        'judged_by',
        'judged_at',
    ];

    protected $casts = [
        'week_start' => 'date',
        'judged_at'  => 'datetime',
    ];

    /**
     * Store the week start as a bare Y-m-d — same reason as DsPlanDay::plan_date:
     * the `date` cast writes a full datetime, which makes an equality lookup miss
     * on SQLite and turns updateOrCreate into a unique-key violation.
     */
    public function setWeekStartAttribute($value): void
    {
        $this->attributes['week_start'] = Carbon::parse($value)->toDateString();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function judgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judged_by');
    }
}
