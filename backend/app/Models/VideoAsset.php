<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoAsset extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['id' => 'string', 'duration_seconds' => 'integer']; }
}
