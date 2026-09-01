<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationItemValue extends Model
{
    protected $fillable = [
        'evaluation_id',
        'inspection_item_id',
        'value',
        'weight',
        'note',
    ];

    protected $casts = [
        'weight' => 'integer',
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InspectionItem::class, 'inspection_item_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EvaluationItemValueAttachment::class);
    }
}
