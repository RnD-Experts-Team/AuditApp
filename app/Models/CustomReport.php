<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomReport extends Model
{
    protected $fillable = [
        'name',
        'active',
        'created_by',
    ];

    public function entities()
    {
        return $this->belongsToMany(
            Entity::class,
            'custom_report_entities'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
