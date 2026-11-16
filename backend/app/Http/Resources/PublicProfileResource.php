<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->name,
            'statistics' => [
                'reviews_count' => (int) $this->public_reviews_count,
                'public_lists_count' => (int) $this->public_lists_count,
            ],
            'followers_count' => (int) $this->followers_count,
            'following_count' => (int) $this->following_count,
            'reviews' => ReviewResource::collection($this->publicReviews),
            'public_lists' => PublicListSummaryResource::collection($this->publicLists),
        ];
    }
}
