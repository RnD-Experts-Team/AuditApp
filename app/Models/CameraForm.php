<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CameraForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entity_id',
        'audit_id',
        'rating_id',
    ];

    protected $with = [
        'notes',
    ];

    public function notes(): HasMany
    {
        return $this->hasMany(CameraFormNote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }
}
