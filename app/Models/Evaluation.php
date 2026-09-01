<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evaluation extends Model
{
    protected $fillable = [
        'store_id',
        'period_type',
        'period_key',
        'created_by',
        'finalized_at',
        'finalized_by',
        'item_score',
        'chart_score',
        'final_score',
        'score_formula',
    ];

    protected $casts = [
        'finalized_at' => 'datetime',
        'item_score'   => 'float',
        'chart_score'  => 'float',
        'final_score'  => 'float',
    ];

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function itemValues(): HasMany
    {
        return $this->hasMany(EvaluationItemValue::class);
    }

    public function chartVerdicts(): HasMany
    {
        return $this->hasMany(EvaluationChartVerdict::class);
    }
}
