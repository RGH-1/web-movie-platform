<?php

namespace App\Support;

final class MovieGenres
{
    public const IDS_BY_SLUG = [
        'action' => 28,
        'adventure' => 12,
        'animation' => 16,
        'comedy' => 35,
        'crime' => 80,
        'documentary' => 99,
        'drama' => 18,
        'family' => 10751,
        'fantasy' => 14,
        'history' => 36,
        'horror' => 27,
        'music' => 10402,
        'mystery' => 9648,
        'romance' => 10749,
        'science-fiction' => 878,
        'tv-movie' => 10770,
        'thriller' => 53,
        'war' => 10752,
        'western' => 37,
    ];

    /** @return list<int> */
    public static function ids(): array
    {
        return array_values(self::IDS_BY_SLUG);
    }

    public static function slugForId(int $id): ?string
    {
        $slug = array_search($id, self::IDS_BY_SLUG, true);

        return $slug === false ? null : $slug;
    }
}