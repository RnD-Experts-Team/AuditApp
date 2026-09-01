<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slice of an absent task's weight, handed to a task that IS in play for
 * this store + period. See the migration for why this is a pair table and not a
 * weight override.
 */
class CleaningWeightAllocation extends Model
{
    protected $fillable = [
        'store_id',
        'period_type',
        'period_key',
        'source_task_id',
        'target_task_id',
        'amount',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sourceTask(): BelongsTo
    {
        return $this->belongsTo(CleaningTask::class, 'source_task_id')->withTrashed();
    }

    public function targetTask(): BelongsTo
    {
        return $this->belongsTo(CleaningTask::class, 'target_task_id')->withTrashed();
    }
}
