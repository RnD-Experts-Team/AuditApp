<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
