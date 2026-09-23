<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationChartVerdict extends Model
{
    protected $fillable = [
        'evaluation_id',
        'cleaning_task_id',
        'frequency',
        'weight',
        'verdict',
        'source',
        'note',
    ];

    protected $casts = [
        'weight' => 'integer',
    ];

    /**
     * Was this fail decided by the system (the store never marked the task
     * complete) rather than by a person who looked at the work?
     *
     * A null `source` means "auditor": every row written before the column
     * existed was entered by hand, and nothing else can write a null now.
     */
    public function isSystemVerdict(): bool
    {
        return $this->source === 'system';
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EvaluationChartVerdictAttachment::class);
    }
}
