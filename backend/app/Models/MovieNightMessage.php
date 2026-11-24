<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovieNightMessage extends Model
{
    protected $fillable = ['text', 'video_timestamp_seconds', 'is_spoiler'];

    protected function casts(): array
    {
        return [
            'video_timestamp_seconds' => 'float',
            'is_spoiler' => 'boolean',
        ];
    }
}
