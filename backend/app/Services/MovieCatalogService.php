<?php

namespace App\Services;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MovieCatalogService
{
    public const METADATA = [
        'title', 'overview', 'release_date', 'runtime_minutes', 'genres',
        'poster_url', 'backdrop_url', 'director', 'cast', 'vote_average', 'trailer',
    ];

    /** Resolve for user interactions without requiring callers to know TMDB normalization. */
    public function resolveTmdbId(int $tmdbId): Movie
    {
        if ($tmdbId < 1 || $tmdbId > 2147483647) {
            throw new InvalidArgumentException('Invalid TMDB movie ID.');
        }

        $existing = Movie::where('tmdb_id', $tmdbId)->first();

        return $existing ?? $this->resolveDetails(app(TmdbService::class)->getMovieDetails($tmdbId));
    }

    /** Accept only the normalized details contract returned by TmdbService. */
    public function resolveDetails(array $details): Movie
    {
        $id = $details['tmdb_id'] ?? null;
        if (!is_int($id) || $id < 1 || $id > 2147483647 ||
            !is_string($details['title'] ?? null) || trim($details['title']) === '' ||
            mb_strlen(trim($details['title'])) > 255) {
            throw new \UnexpectedValueException('Invalid normalized movie details.');
        }

        return DB::transaction(function () use ($id, $details): Movie {
            // createOrFirst handles a concurrent insert using the unique tmdb_id index.
            $movie = Movie::query()->createOrFirst(
                ['tmdb_id' => $id],
                array_intersect_key($details, array_flip(self::METADATA)) + [
                    'source' => 'tmdb', 'tmdb_synced_at' => now(),
                ],
            );
            $movie = Movie::whereKey($movie->id)->lockForUpdate()->firstOrFail();

            if ($movie->deactivated_at !== null) {
                return $movie;
            }

            foreach (self::METADATA as $field) {
                if (array_key_exists($field, $details) && !in_array($field, $movie->metadata_overrides, true)) {
                    $movie->setAttribute($field, $details[$field]);
                }
            }
            $movie->tmdb_synced_at = now();
            $movie->save();

            return $movie;
        });
    }

    /** Annotate discovery results in one query; never import search/feed results. */
    public function attachLocalIds(array $movies): array
    {
        if ($movies === []) {
            return [];
        }
        $ids = Movie::active()->whereIn('tmdb_id', array_column($movies, 'tmdb_id'))
            ->pluck('id', 'tmdb_id');
        foreach ($movies as &$movie) {
            $id = $ids->get($movie['tmdb_id']);
            $movie['local_movie_id'] = $id === null ? null : (string) $id;
            $movie['source'] = 'tmdb';
        }
        return $movies;
    }

    public function details(Movie $movie): array
    {
        $data = $movie->only(self::METADATA);
        $data['release_date'] = $movie->release_date?->format('Y-m-d');

        return $data + [
            'tmdb_id' => $movie->tmdb_id,
            'local_movie_id' => (string) $movie->id,
            'source' => $movie->source,
        ];
    }
}
