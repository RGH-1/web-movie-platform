<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovieNightInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
        ];
    }
}
