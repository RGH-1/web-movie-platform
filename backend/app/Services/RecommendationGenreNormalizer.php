<?php

namespace App\Services;

use Illuminate\Support\Str;

class RecommendationGenreNormalizer
{
    public const GENRES = [
        'action' => 28, 'adventure' => 12, 'animation' => 16, 'comedy' => 35,
        'crime' => 80, 'documentary' => 99, 'drama' => 18, 'fantasy' => 14,
        'horror' => 27, 'mystery' => 9648, 'romance' => 10749,
        'science-fiction' => 878, 'thriller' => 53,
    ];

    /** @param array<int, mixed> $genres @return list<string> */
    public function normalize(array $genres): array
    {
        $result = [];
        foreach ($genres as $genre) {
            $slug = null;
            if (is_array($genre)) {
                $id = $genre['tmdb_id'] ?? $genre['id'] ?? null;
                $slug = is_int($id) ? array_search($id, self::GENRES, true) : null;
                $slug ??= is_string($genre['name'] ?? null) ? $this->slug($genre['name']) : null;
            } elseif (is_string($genre)) {
                $slug = $this->slug($genre);
            }
            if ($slug !== null && isset(self::GENRES[$slug])) {
                $result[$slug] = true;
            }
        }

        return array_keys($result);
    }

    public function slug(string $name): ?string
    {
        $slug = Str::slug(trim($name));

        return $slug === '' ? null : $slug;
    }
}
