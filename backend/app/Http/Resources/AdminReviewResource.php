<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminReviewResource extends ReviewResource
{
    public function toArray(Request $request): array
    {
        $movie = $this->movie;
        $moderator = $this->moderator;

        return [
            ...parent::toArray($request),

            'movie' => $movie === null ? null : [
                'local_movie_id' => (string) $movie->getKey(),
                'title' => $movie->title,
                'source' => $movie->source,
                'tmdb_id' => $movie->tmdb_id,
                'is_active' => $movie->deactivated_at === null,
            ],

            'moderation' => [
                'status' => $this->moderation_status,
                'reason' => $this->moderation_reason,
                'moderated_at' => $this->moderated_at?->toISOString(),
                'moderator' => $moderator === null ? null : [
                    'id' => (string) $moderator->getKey(),
                    'name' => $moderator->name,
                ],
            ],
        ];
    }
}