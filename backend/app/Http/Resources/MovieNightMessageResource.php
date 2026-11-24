<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovieNightMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'movie_night_id' => (string) $this->movie_night_id,
            'sender_id' => (string) $this->sender_id,
            'sender_name' => $this->sender?->name,
            'text' => $this->text,
            'video_timestamp_seconds' => $this->video_timestamp_seconds,
            'is_spoiler' => $this->is_spoiler,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
