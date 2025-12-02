<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'group',
    ];

    protected $casts = [
        'group' => 'integer',
    ];

    /**
     * Get the user that owns this group assignment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
