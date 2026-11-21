<?php

namespace App\Services;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use App\Models\UserMovieStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecommendationService
{
    private float $providerDeadline;

    public function __construct(
        private RecommendationGenreNormalizer $genres,
        private RecommendationScorer $scorer,
        private WeatherService $weather,
        private RecommendationProviderCache $providerCache,
    ) {}

    /** @return array{data: list<array<string,mixed>>, meta: array<string,mixed>} */
    public function recommend(User $user, int $limit, mixed $latitude = null, mixed $longitude = null): array
    {
        $this->providerDeadline = microtime(true) + 8;
        // A missing coordinate pair is intentionally not a weather lookup.  The
        // public recommendation contract has no default city.
        $weather = $latitude === null
            ? ['status' => 'not_requested', 'condition' => null, 'temperature_c' => null, 'valid_at' => null]
            : $this->weather->forCoordinates($latitude, $longitude);
        $warnings = $weather['status'] === 'unavailable' ? ['WEATHER_UNAVAILABLE'] : [];
        if (($weather['_provider_budget_exhausted'] ?? false) === true) {
            $warnings[] = 'PROVIDER_BUDGET_EXHAUSTED';
        }
        unset($weather['_provider_budget_exhausted']);
        $context = $this->context($user, $weather);
        $excluded = $this->exclusions($user);
        $movies = $this->localCandidates($context, $excluded);
        $candidates = $movies->map(fn (Movie $movie) => $this->candidate($movie));
        [$providerCandidates, $providerWarnings] = $this->providerCandidates($context, $excluded);
        $warnings = array_values(array_unique([...$warnings, ...$providerWarnings]));
        $candidates = $candidates->concat($providerCandidates)
            ->unique(fn (array $candidate) => $candidate['local_movie_id'] !== null ? 'local:'.$candidate['local_movie_id'] : 'tmdb:'.$candidate['tmdb_id'])
            ->take(260)
            ->values();
        [$candidates, $enrichmentWarnings] = $this->enrich($candidates, $context);
        $warnings = array_values(array_unique([...$warnings, ...$enrichmentWarnings]));
        $candidates = $candidates->filter(fn (array $candidate): bool => $candidate['release_date'] === null || $candidate['release_date'] <= now()->utc()->toDateString())->values();
        $scored = $candidates->map(function (array $candidate) use ($context): array {
            return $candidate + ['recommendation' => $this->scorer->score($candidate, $context)];
        })
            ->sort(function (array $left, array $right): int {
                $score = $right['recommendation']['score_raw'] <=> $left['recommendation']['score_raw'];
                if ($score !== 0) {
                    return $score;
                }

                if ($left['local_movie_id'] !== null && $right['local_movie_id'] === null) {
                    return -1;
                }
                if ($left['local_movie_id'] === null && $right['local_movie_id'] !== null) {
                    return 1;
                }

                return $left['local_movie_id'] !== null
                    ? ((int) $left['local_movie_id']) <=> ((int) $right['local_movie_id'])
                    : ((int) $left['tmdb_id']) <=> ((int) $right['tmdb_id']);
            })->take($limit)->values();
        $data = $scored->map(fn (array $candidate): array => $this->output($candidate, $context))->all();
        $personalized = collect(['genre', 'history', 'people', 'similarity'])->contains(fn ($key) => $data !== [] && $data[0]['recommendation']['signals'][$key]['value'] !== null);

        return ['data' => $data, 'meta' => [
            'algorithm_version' => 'weighted-v1', 'requested_limit' => $limit, 'returned_count' => count($data), 'candidate_count' => $candidates->count(),
            'personalization' => $personalized ? 'personalized' : 'general', 'partial' => $warnings !== [], 'warnings' => $warnings,
            'empty_reason' => $data === [] ? ($warnings !== [] ? 'sources_unavailable' : 'no_eligible_candidates') : null, 'weather' => $weather, 'generated_at' => now()->utc()->toISOString(),
        ]];
    }

    /** @return array{0: list<array<string,mixed>>, 1: list<string>} */
    private function providerCandidates(array $context, array $excluded): array
    {
        if (! is_string(config('services.tmdb.token')) || trim((string) config('services.tmdb.token')) === '' || ! is_string(config('services.tmdb.base_url')) || trim((string) config('services.tmdb.base_url')) === '') {
            return [[], ['TMDB_NOT_CONFIGURED']];
        }

        $tmdb = app(TmdbService::class);
        $responses = [];
        $warnings = [];
        $source = function (string $key, int $ttl, callable $fetch) use (&$responses, &$warnings): void {
            if (! $this->canScheduleProviderCall()) {
                $warnings[] = 'PROVIDER_BUDGET_EXHAUSTED';

                return;
            }
            $result = $this->providerCache->remember($key, $ttl, $fetch);
            if ($result['data'] !== null) {
                $responses[] = $result['data'];
            } elseif ($result['state'] === 'budget_exhausted') {
                $warnings[] = 'PROVIDER_BUDGET_EXHAUSTED';
            } else {
                $warnings[] = 'TMDB_UNAVAILABLE';
            }
        };
        $primary = $context['favorites'][0] ?? collect($context['history'])->sortDesc()->keys()->first();
        if ($primary !== null) {
            $source('recommendations:tmdb:discovery:genre:'.$primary, 900, fn () => $tmdb->recommendationDiscover(['genre_id' => RecommendationGenreNormalizer::GENRES[$primary]], $this->remainingProviderTimeout()));
        }
        $source('recommendations:tmdb:discovery:popular', 900, fn () => $tmdb->recommendationDiscover([], $this->remainingProviderTimeout()));
        if (($context['tmdb_anchor'] ?? null) !== null) {
            $anchor = $context['tmdb_anchor'];
            $source('recommendations:tmdb:similar:'.$anchor, 3600, fn () => $tmdb->recommendationSimilarMovies($anchor, $this->remainingProviderTimeout()));
        }

        $raw = collect($responses)->flatMap(fn (array $feed) => $feed['data'] ?? [])->take(60)
            ->filter(fn (array $movie) => trim((string) ($movie['title'] ?? '')) !== '')
            ->unique('tmdb_id')->values();
        $mapped = Movie::query()->whereIn('tmdb_id', $raw->pluck('tmdb_id'))->get()->keyBy('tmdb_id');
        $candidates = [];
        foreach ($raw as $movie) {
            $mapping = $mapped->get($movie['tmdb_id']);
            if ($mapping !== null) {
                // Stored metadata is authoritative; inactive or excluded mappings
                // must not return through a provider card.
                continue;
            }
            if ($movie['release_date'] !== null && $movie['release_date'] > now()->utc()->toDateString()) {
                continue;
            }
            $genres = array_map(fn (int $id): array => ['tmdb_id' => $id, 'name' => array_search($id, RecommendationGenreNormalizer::GENRES, true) ?: 'Unknown'], $movie['genre_ids']);
            $candidates[] = [
                'local_movie_id' => null, 'tmdb_id' => $movie['tmdb_id'], 'source' => 'tmdb', 'title' => $movie['title'],
                'overview' => $movie['overview'], 'poster_url' => $movie['poster_url'], 'release_date' => $movie['release_date'],
                'vote_average' => $movie['vote_average'], 'genres' => $genres, 'genre_slugs' => $this->genres->normalize($genres),
                'cast' => [], 'director' => null, 'runtime_minutes' => null,
                'year' => $movie['release_date'] === null ? null : (int) substr($movie['release_date'], 0, 4),
                'platform_rating' => ['average' => null, 'count' => 0],
            ];
        }

        return [$candidates, array_values(array_unique($warnings))];
    }

    /** @return array{0: Collection<int,array<string,mixed>>, 1: list<string>} */
    private function enrich(Collection $candidates, array $context): array
    {
        if (! is_string(config('services.tmdb.token')) || trim((string) config('services.tmdb.token')) === '' || ! is_string(config('services.tmdb.base_url')) || trim((string) config('services.tmdb.base_url')) === '') {
            return [$candidates, []];
        }
        $tmdb = app(TmdbService::class);
        $warnings = [];
        $selected = $candidates->filter(fn (array $candidate): bool => $candidate['local_movie_id'] === null)
            ->map(fn (array $candidate): array => $candidate + ['_preliminary' => $this->scorer->score($candidate, $context)['score_raw']])
            ->sortByDesc('_preliminary')->take(3);
        foreach ($selected as $selectedCandidate) {
            if (! $this->canScheduleProviderCall()) {
                $warnings[] = 'ENRICHMENT_INCOMPLETE';

                break;
            }
            $id = (int) $selectedCandidate['tmdb_id'];
            $result = $this->providerCache->remember('recommendations:tmdb:details:'.$id, 86400, fn () => $tmdb->recommendationMovieDetails($id, $this->remainingProviderTimeout()));
            if ($result['data'] === null) {
                $warnings[] = $result['state'] === 'budget_exhausted' ? 'PROVIDER_BUDGET_EXHAUSTED' : 'ENRICHMENT_INCOMPLETE';

                continue;
            }
            $detail = $result['data'];
            $candidates = $candidates->map(function (array $candidate) use ($id, $detail): array {
                if ($candidate['local_movie_id'] !== null || (int) $candidate['tmdb_id'] !== $id) {
                    return $candidate;
                }
                $genres = is_array($detail['genres'] ?? null) ? $detail['genres'] : $candidate['genres'];
                $releaseDate = $detail['release_date'] ?? $candidate['release_date'];

                return array_replace($candidate, [
                    'overview' => $detail['overview'] ?? $candidate['overview'],
                    'poster_url' => $detail['poster_url'] ?? $candidate['poster_url'],
                    'release_date' => $releaseDate,
                    'vote_average' => $detail['vote_average'] ?? $candidate['vote_average'],
                    'genres' => $genres,
                    'genre_slugs' => $this->genres->normalize($genres),
                    'cast' => is_array($detail['cast'] ?? null) ? $detail['cast'] : [],
                    'director' => $detail['director'] ?? null,
                    'runtime_minutes' => $detail['runtime_minutes'] ?? null,
                    'year' => is_string($releaseDate) ? (int) substr($releaseDate, 0, 4) : null,
                ]);
            });
        }

        return [$candidates, array_values(array_unique($warnings))];
    }

    private function canScheduleProviderCall(): bool
    {
        // A 500ms floor prevents starting a request that cannot complete in
        // the remaining global external-I/O window.  TmdbService itself caps
        // each transport call at two seconds and never retries.
        return microtime(true) + .5 < $this->providerDeadline;
    }

    private function remainingProviderTimeout(): float
    {
        return max(0.001, min(2.0, $this->providerDeadline - microtime(true)));
    }

    /** @return Collection<int, Movie> */
    private function localCandidates(array $context, array $excluded): Collection
    {
        $base = Movie::query()->active()
            ->whereNotIn('id', $excluded)
            ->where(fn ($query) => $query->whereNull('release_date')->orWhere('release_date', '<=', now()->utc()->toDateString()))
            ->withCount(['reviews as platform_rating_count' => fn ($query) => $query->where('moderation_status', Review::STATUS_VISIBLE)])
            ->withAvg(['reviews as platform_rating_average' => fn ($query) => $query->where('moderation_status', Review::STATUS_VISIBLE)], 'rating');

        $affinities = array_values(array_unique([...$context['favorites'], ...array_keys($context['history'])]));
        $genre = collect();
        if ($affinities !== []) {
            $genre = (clone $base)->where(function ($query) use ($affinities): void {
                foreach ($affinities as $slug) {
                    $id = RecommendationGenreNormalizer::GENRES[$slug] ?? null;
                    if ($id !== null) {
                        if (DB::getDriverName() === 'sqlite') {
                            $query->orWhereRaw("EXISTS (SELECT 1 FROM json_each(movies.genres) WHERE json_extract(json_each.value, '$.tmdb_id') = ? OR lower(json_extract(json_each.value, '$.name')) = ?)", [$id, str_replace('-', ' ', $slug)]);
                        } else {
                            $query->orWhereRaw("JSON_SEARCH(genres, 'one', ?, NULL, '$[*].tmdb_id') IS NOT NULL OR EXISTS (SELECT 1 FROM JSON_TABLE(genres, '$[*]' COLUMNS(name VARCHAR(255) PATH '$.name')) AS genre_names WHERE LOWER(genre_names.name) = ?)", [(string) $id, str_replace('-', ' ', $slug)]);
                        }
                    }
                }
            })->orderByDesc('updated_at')->orderByDesc('id')->limit(100)->get();
        }

        // withCount/withAvg generate scalar subqueries, so this stays a
        // bounded SQL sort and does not materialise the catalog in PHP.
        $quality = (clone $base)
            ->orderByRaw('((platform_rating_count * (COALESCE(platform_rating_average, 3) / 5.0)) + (5 * COALESCE(vote_average / 10.0, 0.6))) / (platform_rating_count + 5) DESC')
            ->orderByDesc('id')->limit(100)->get();
        $selected = $genre->concat($quality)->unique('id')->values();
        $remaining = 200 - $selected->count();
        if ($remaining > 0) {
            $fill = (clone $base)->whereNotIn('id', $selected->pluck('id'))
                ->orderByDesc('id')->limit($remaining)->get();
            $selected = $selected->concat($fill)->unique('id')->values();
        }

        return $selected;
    }

    /** @return array<string,mixed> */
    private function context(User $user, array $weather): array
    {
        $favorites = $this->genres->normalize(is_array($user->favorite_genres) ? $user->favorite_genres : []);
        $watched = UserMovieStatus::query()->where('user_id', $user->id)->whereNotNull('watched_at')->with('movie:id,genres,deactivated_at')->orderByDesc('watched_at')->orderByDesc('id')->limit(100)->get();
        $ratings = Review::query()->where('user_id', $user->id)->where('moderation_status', Review::STATUS_VISIBLE)->pluck('rating', 'movie_id');
        $history = [];
        foreach ($watched as $status) {
            if ($status->movie === null || $status->movie->deactivated_at !== null) {
                continue;
            }
            $weight = match ((int) ($ratings[$status->movie_id] ?? 0)) {
                5 => 1, 4 => .75, 3 => .25, 1, 2 => 0, default => .5
            };
            foreach ($this->genres->normalize($status->movie->genres) as $genre) {
                $history[$genre] = ($history[$genre] ?? 0) + $weight;
            }
        }
        $maximum = $history === [] ? 0 : max($history);
        $history = $maximum > 0 ? collect($history)->map(fn ($value) => $value / $maximum)->all() : [];
        $anchors = Review::query()->where('user_id', $user->id)->where('moderation_status', Review::STATUS_VISIBLE)->whereIn('rating', [4, 5])->with('movie:id,genres,cast,director,runtime_minutes,release_date,deactivated_at')->orderByDesc('rating')->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();
        $actors = [];
        $directors = [];
        $similarity = [];
        $actorWeight = 0.0;
        $directorWeight = 0.0;
        foreach ($anchors as $review) {
            $movie = $review->movie;
            if ($movie === null || $movie->deactivated_at !== null) {
                continue;
            }
            $weight = ($review->rating - 3) / 2;
            $cast = is_array($movie->cast) ? $movie->cast : [];
            if ($cast !== []) {
                $actorWeight += $weight;
                foreach ($cast as $person) {
                    if (! is_array($person)) {
                        continue;
                    }
                    $key = $person['tmdb_person_id'] ?? strtolower(trim((string) ($person['name'] ?? '')));
                    if ($key !== '') {
                        $actors[$key] = ($actors[$key] ?? 0) + $weight;
                    }
                }
            }
            if (is_string($movie->director) && trim($movie->director) !== '') {
                $directorWeight += $weight;
                $key = strtolower(trim($movie->director));
                $directors[$key] = ($directors[$key] ?? 0) + $weight;
            }
            $genre = $this->genres->normalize($movie->genres);
            if ($genre !== []) {
                $similarity[] = ['genres' => $genre, 'runtime_minutes' => $movie->runtime_minutes, 'year' => $movie->release_date?->year];
            }
        }
        if ($actorWeight > 0) {
            $actors = collect($actors)->map(fn ($v) => $v / $actorWeight)->all();
        } else {
            $actors = [];
        }
        if ($directorWeight > 0) {
            $directors = collect($directors)->map(fn ($v) => $v / $directorWeight)->all();
        } else {
            $directors = [];
        }

        return compact('favorites', 'history', 'weather') + [
            'actor_affinity' => $actors,
            'director_affinity' => $directors,
            'similarity_anchors' => array_slice($similarity, 0, 3),
            'tmdb_anchor' => $anchors->first()?->movie?->tmdb_id,
        ];
    }

    /** @return list<string> */
    private function exclusions(User $user): array
    {
        return array_values(array_unique(array_merge(UserMovieStatus::query()->where('user_id', $user->id)->whereNotNull('watched_at')->pluck('movie_id')->all(), Review::query()->where('user_id', $user->id)->pluck('movie_id')->all())));
    }

    /** @return array<string,mixed> */
    private function candidate(Movie $movie): array
    {
        return [
            'local_movie_id' => (string) $movie->id,
            'tmdb_id' => $movie->tmdb_id,
            'source' => $movie->source,
            'title' => $movie->title,
            'overview' => $movie->overview,
            'poster_url' => $movie->poster_url,
            'release_date' => $movie->release_date?->format('Y-m-d'),
            'vote_average' => $movie->vote_average,
            'genres' => $movie->genres,
            'genre_slugs' => $this->genres->normalize($movie->genres),
            'cast' => $movie->cast,
            'director' => $movie->director,
            'runtime_minutes' => $movie->runtime_minutes,
            'year' => $movie->release_date?->year,
            'platform_rating' => [
                'average' => $movie->platform_rating_average === null ? null : (float) $movie->platform_rating_average,
                'count' => (int) $movie->platform_rating_count,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function output(array $candidate, array $context): array
    {
        $recommendation = $candidate['recommendation'];
        unset($recommendation['score_raw']);
        $reasons = [];
        $matchingFavorites = array_values(array_intersect($candidate['genre_slugs'], $context['favorites']));
        $historyGenres = array_values(array_filter($candidate['genre_slugs'], fn (string $genre): bool => ($context['history'][$genre] ?? 0) > 0));

        if ($recommendation['signals']['weather']['value'] >= .7 && $candidate['genre_slugs'] !== []) {
            $reasons[] = ['code' => 'weather_match', 'message' => 'Matches the current weather selection.', 'params' => [
                'condition' => $context['weather']['condition'], 'genres' => $candidate['genre_slugs'],
            ]];
        }
        if ($matchingFavorites !== []) {
            $reasons[] = ['code' => 'genre_preference', 'message' => 'Matches your favorite genres.', 'params' => ['genres' => $matchingFavorites]];
        }
        if ($candidate['platform_rating']['count'] > 0 && $candidate['platform_rating']['average'] !== null) {
            $reasons[] = ['code' => 'community_rating', 'message' => 'Rated by the community.', 'params' => [
                'average' => round($candidate['platform_rating']['average'], 2), 'count' => $candidate['platform_rating']['count'],
            ]];
        } elseif (is_numeric($candidate['vote_average']) && $candidate['vote_average'] > 0) {
            $reasons[] = ['code' => 'tmdb_rating', 'message' => 'Has a TMDB community rating.', 'params' => ['average' => round((float) $candidate['vote_average'], 2)]];
        }
        if ($historyGenres !== []) {
            $reasons[] = ['code' => 'watched_history', 'message' => 'Matches genres in your watched history.', 'params' => ['genres' => $historyGenres]];
        }
        if ($recommendation['signals']['people']['value'] > 0 && $recommendation['signals']['people']['evidence'] === 'observed') {
            $reasons[] = ['code' => 'people_match', 'message' => 'Shares people with movies you liked.', 'params' => (object) []];
        }
        if ($recommendation['signals']['similarity']['value'] > 0 && $recommendation['signals']['similarity']['evidence'] === 'observed') {
            $reasons[] = ['code' => 'similar_to_liked', 'message' => 'Is similar to movies you liked.', 'params' => (object) []];
        }
        if ($reasons === []) {
            $reasons[] = ['code' => 'general_discovery', 'message' => 'A general discovery pick.', 'params' => (object) []];
        }
        $card = array_intersect_key($candidate, array_flip(['local_movie_id', 'tmdb_id', 'source', 'title', 'overview', 'poster_url', 'release_date', 'vote_average', 'genres', 'platform_rating']));

        return $card + ['is_active' => true, 'recommendation' => $recommendation + ['reasons' => array_slice($reasons, 0, 3)]];
    }
}
