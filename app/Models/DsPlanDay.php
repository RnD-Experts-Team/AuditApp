<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One confirmed daily plan line: store + production date + ingredient.
 *
 * The row existing means the plan is confirmed. There is no draft state here —
 * before confirmation the numbers live only in the browser, recomputed from
 * LC_PIZZA_DATA every time the manager changes his buffer.
 */
class DsPlanDay extends Model
{
    protected $fillable = [
        'store_id',
        'plan_date',
        'ingredient_key',
        'buffer_pct',
        'planned_qty',
        'confirmed_at',
        'confirmed_by',
    ];

    protected $casts = [
        'plan_date'    => 'date',
        'buffer_pct'   => 'decimal:2',
        'planned_qty'  => 'decimal:4',
        'confirmed_at' => 'datetime',
    ];

    /**
     * Store the production date as a bare Y-m-d.
     *
     * The `date` cast alone writes "2026-09-25 00:00:00", because Eloquent
     * serialises every date attribute with the full datetime format. MySQL then
     * truncates it on the way into a DATE column and lookups happen to match;
     * SQLite keeps the string verbatim, so `where('plan_date', '2026-09-25')`
     * finds nothing — updateOrCreate falls through to an insert and hits the
     * unique key instead of updating.
     *
     * That difference is exactly the kind that passes in production and fails in
     * tests, or the reverse. Writing the canonical form removes it.
     */
    public function setPlanDateAttribute($value): void
    {
        $this->attributes['plan_date'] = Carbon::parse($value)->toDateString();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The figure LC_PIZZA_DATA gave us before the manager's buffer was applied.
     *
     * Not a column: storing it would be a second copy of a number that is already
     * implied by the two we keep, and copies drift. One division recovers it
     * exactly, and "exactly" matters — this is what the plan screen shows when
     * someone asks where 682 came from.
     */
    public function getBaseQtyAttribute(): float
    {
        return (float) $this->planned_qty / (1 + (float) $this->buffer_pct / 100);
    }
}
