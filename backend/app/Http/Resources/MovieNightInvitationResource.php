<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovieNightInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $night = $this->movieNight;
        return [
            'id' => (string) $this->id,
            'movie_night_id' => (string) $this->movie_night_id,
            'invitee' => [
                'id' => (string) $this->invitee_id,
                'name' => $this->invitee?->name,
            ],
            'inviter_id' => (string) $this->inviter_id,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'movie_night_preview' => $night === null ? null : [
                'id' => (string) $night->id,
                'title' => $night->title,
                'description' => $night->description,
                'starts_at' => $night->starts_at?->toISOString(),
                'timezone' => $night->timezone,
                'location' => $night->location,
                'meeting_url' => $night->meeting_url,
                'status' => $night->status,
                'organizer' => [
                    'id' => (string) $night->organizer_id,
                    'name' => $night->organizer?->name,
                ],
            ],
        ];
    }
}
