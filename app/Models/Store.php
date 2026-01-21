<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $fillable = [
        'id',
        'store',
        'group',
    ];

    protected $casts = [
        'group' => 'integer',
    ];

    public function cameraForms(): HasMany
    {
        return $this->hasMany(CameraForm::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class);
    }
}
