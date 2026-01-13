<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CameraFormNote extends Model
{
    protected $fillable = [
        'camera_form_id',
        'note',
    ];

    protected $with = [
        'attachments',
    ];

    public function cameraForm(): BelongsTo
    {
        return $this->belongsTo(CameraForm::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CameraFormNoteAttachment::class, 'camera_form_note_id');
    }
}
