<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovieNightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $night = $this->resource;
        $organizer = $night->organizer;
        $winner = $night->winningMovie;

        $isOrganizer = (string) $request->user()->getKey()
            === (string) $night->organizer_id;

        return [
            'id' => (string) $night->getKey(),
            'organizer' => [
                'id' => (string) $organizer->getKey(),
                'name' => $organizer->name,
            ],
            'title' => $night->title,
            'description' => $night->description,
            'starts_at' => $night->starts_at->utc()->toISOString(),
            'timezone' => $night->timezone,
            'location' => $night->location,
            'meeting_url' => $night->meeting_url,
            'status' => $night->status,
            'winning_movie_id' => $night->winning_movie_id,
            'winning_movie' => $winner === null ? null : [
                'local_movie_id' => (string) $winner->getKey(),
                'tmdb_id' => $winner->tmdb_id,
                'source' => $winner->source,
                'title' => $winner->title,
                'is_active' => $winner->deactivated_at === null,
            ],
            'viewer_role' => $isOrganizer ? 'organizer' : 'participant',
            'permissions' => [
                'update' => $isOrganizer && $night->isEditable(),
                'cancel' => $isOrganizer && $night->canBeCancelled(),
            ],
            'summaries' => [
                'participants' => [
                    'count' => (int) $night->participants_count,
                ],
                'invitations' => null,
                'shortlist' => null,
            ],
            'created_at' => $night->created_at?->toISOString(),
            'updated_at' => $night->updated_at?->toISOString(),
        ];
    }
}