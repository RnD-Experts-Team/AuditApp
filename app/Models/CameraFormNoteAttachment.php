<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CameraFormNoteAttachment extends Model
{
    protected $fillable = [
        'camera_form_note_id',
        'path',
    ];

    protected $appends = [
        'url',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(CameraFormNote::class, 'camera_form_note_id');
    }

    public function getUrlAttribute(): ?string
    {
        if (!$this->path) return null;
        return Storage::disk('public')->url($this->path);
    }
}
