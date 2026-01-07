<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_label',
        'category_id',
        'date_range_type',
        'report_type',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'date_range_type' => 'string',
        'report_type' => 'string',
        'sort_order' => 'integer',
        'active' => 'boolean',
    ];

    /**
     * Get the category that owns the entity.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get all camera forms for this entity.
     */
    public function cameraForms(): HasMany
    {
        return $this->hasMany(CameraForm::class);
    }
}
