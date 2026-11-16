<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->getKey(), 'name' => $this->name,
            'followers_count' => (int) $this->followers_count, 'following_count' => (int) $this->following_count,
            'is_following' => (bool) $this->is_following];
    }
}
