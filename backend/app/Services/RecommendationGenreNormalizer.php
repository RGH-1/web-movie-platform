<?php

namespace App\Services;

use App\Support\MovieGenres;
use Illuminate\Support\Str;

class RecommendationGenreNormalizer
{
    public const GENRES = MovieGenres::IDS_BY_SLUG;

    /** @param array<int, mixed> $genres @return list<string> */
    public function normalize(array $genres): array
    {
        $result = [];

        foreach ($genres as $genre) {
            $id = null;
            $name = null;

            if (is_int($genre)) {
                $id = $genre;
            } elseif (is_array($genre)) {
                $id = $genre['tmdb_id'] ?? $genre['id'] ?? null;
                $name = $genre['name'] ?? null;
            } elseif (is_string($genre)) {
                $name = $genre;
            }

            $slug = is_int($id)
                ? MovieGenres::slugForId($id)
                : null;

            if ($slug === null && is_string($name)) {
                $slug = $this->slug($name);
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