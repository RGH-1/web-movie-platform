<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicListSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->name,
            'description' => $this->description,
            'movies_count' => (int) $this->movies_count,
        ];
    }
}
