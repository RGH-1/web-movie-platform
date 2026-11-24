<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieNightMessage extends Model
{
    protected $fillable = ['text', 'video_timestamp_seconds', 'is_spoiler'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    protected function casts(): array
    {
        return [
            'video_timestamp_seconds' => 'float',
            'is_spoiler' => 'boolean',
        ];
    }
}
