<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $author = $this->user;

        return [
            'id' => (string) $this->getKey(),
            'movie_id' => $this->movie_id,
            'rating' => $this->rating,
            'review_text' => $this->review_text,
            'author' => $author === null ? null : [
                'id' => (string) $author->getKey(),
                'name' => $author->name,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
