<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use App\Exceptions\TmdbRequestException;
use RuntimeException;
use InvalidArgumentException;
use UnexpectedValueException;


class TmdbService
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $baseUrl = config('services.tmdb.base_url');
        $token = config('services.tmdb.token');

        if (
            !is_string($baseUrl) || trim($baseUrl) === '' ||
            !is_string($token) || trim($token) === ''
        ) {
            throw new RuntimeException(
                'TMDB API configuration is missing.'
            );
        }

        $this->baseUrl = $baseUrl;
        $this->token = $token;
    }

    public function get(string $endpoint, array $query = []): Response
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get(
                    rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/'),
                    $query
                );
        } catch (ConnectionException $exception) {
            throw new TmdbRequestException(
                'Unable to connect to the movie information service.',
                previous: $exception,
            );
        }

        if ($response->status() === 429) {
            $retryAfter = filter_var(
                $response->header('Retry-After'),
                FILTER_VALIDATE_INT
            );

            throw new RuntimeException(
                'TMDB_RATE_LIMITED:' . (
                $retryAfter !== false && $retryAfter > 0
                    ? $retryAfter
                    : 30
                ),
                429
            );
        }

        if ($response->failed()) {
            throw new TmdbRequestException(
                'The movie information service returned an error.',
                upstreamStatus: $response->status(),
                retryAfter: $response->header('Retry-After') ?: null,
            );
        }

        return $response;
    }


    public function getMovieDetails(int $movieId): array
    {
        if ($movieId < 1 || $movieId > 2147483647) {
            throw new InvalidArgumentException(
                'The TMDB movie ID must be between 1 and 2147483647.'
            );
        }

        $response = $this->get("/movie/{$movieId}", [
            'language' => 'en-US',
            'append_to_response' => 'credits,videos',
        ]);

        $movie = $response->json();

        if (
            ! $response->ok()
            || ! is_array($movie)
            || ($movie['id'] ?? null) !== $movieId
            || $this->nullableText($movie['title'] ?? null) === null
        ) {
            throw new UnexpectedValueException(
                'The movie information service returned an invalid movie response.'
            );
        }

        $credits = is_array($movie['credits'] ?? null)
            ? $movie['credits']
            : [];

        $videos = is_array($movie['videos'] ?? null)
            ? $movie['videos']
            : [];

        $runtime = $movie['runtime'] ?? null;

        return [
            'tmdb_id' => $movieId,
            'title' => $this->nullableText($movie['title']),
            'overview' => $this->nullableText($movie['overview'] ?? null),
            'release_date' => $this->releaseDate($movie['release_date'] ?? null),
            'runtime_minutes' => is_int($runtime) && $runtime > 0
                ? $runtime
                : null,
            'genres' => $this->movieGenres($movie['genres'] ?? null),
            'poster_url' => $this->imageUrl($movie['poster_path'] ?? null, 'w342'),
            'backdrop_url' => $this->imageUrl($movie['backdrop_path'] ?? null, 'w780'),
            'vote_average' => $this->movieRating($movie),
            'cast' => $this->principalCast($credits['cast'] ?? null),
            'director' => $this->movieDirector($credits['crew'] ?? null),
            'trailer' => $this->movieTrailer($videos['results'] ?? null),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function arrayEntries(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function imageUrl(mixed $path, string $size): ?string
    {
        $path = $this->nullableText($path);

        if ($path === null || ! preg_match('~^/[A-Za-z0-9._-]+$~D', $path)) {
            return null;
        }

        return "https://image.tmdb.org/t/p/{$size}{$path}";
    }

    private function releaseDate(mixed $value): ?string
    {
        $date = $this->nullableText($value);

        if ($date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year) ? $date : null;
    }

    private function movieGenres(mixed $genres): array
    {
        $result = [];

        foreach ($this->arrayEntries($genres) as $genre) {
            $id = $genre['id'] ?? null;
            $name = $this->nullableText($genre['name'] ?? null);

            if (is_int($id) && $id > 0 && $name !== null) {
                $result[] = [
                    'tmdb_id' => $id,
                    'name' => $name,
                ];
            }
        }

        return $result;
    }

    private function movieRating(array $movie): ?float
    {
        $voteCount = $movie['vote_count'] ?? null;
        $rating = $movie['vote_average'] ?? null;

        if (
            ! is_int($voteCount)
            || $voteCount <= 0
            || (! is_int($rating) && ! is_float($rating))
            || $rating < 0
            || $rating > 10
        ) {
            return null;
        }

        return (float) $rating;
    }

    private function principalCast(mixed $cast): array
    {
        return collect($this->arrayEntries($cast))
            ->filter(fn (array $person): bool =>
                is_int($person['id'] ?? null)
                && $person['id'] > 0
                && $this->nullableText($person['name'] ?? null) !== null
            )
            ->sortBy(fn (array $person): int =>
                is_int($person['order'] ?? null)
                    ? $person['order']
                    : PHP_INT_MAX
            )
            ->take(8)
            ->map(fn (array $person): array => [
                'tmdb_person_id' => $person['id'],
                'name' => $this->nullableText($person['name']),
                'character' => $this->nullableText($person['character'] ?? null),
                'profile_url' => $this->imageUrl($person['profile_path'] ?? null, 'w185'),
            ])
            ->values()
            ->all();
    }

    private function movieDirector(mixed $crew): ?string
    {
        foreach ($this->arrayEntries($crew) as $person) {
            $name = $this->nullableText($person['name'] ?? null);

            if (($person['job'] ?? null) === 'Director' && $name !== null) {
                return $name;
            }
        }

        return null;
    }

    private function movieTrailer(mixed $videos): ?array
    {
        $fallback = null;

        foreach ($this->arrayEntries($videos) as $video) {
            $key = $this->nullableText($video['key'] ?? null);

            if (
                ($video['site'] ?? null) !== 'YouTube'
                || ($video['type'] ?? null) !== 'Trailer'
                || $key === null
                || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $key)
            ) {
                continue;
            }

            $trailer = [
                'site' => 'YouTube',
                'key' => $key,
            ];

            if (($video['official'] ?? false) === true) {
                return $trailer;
            }

            $fallback ??= $trailer;
        }

        return $fallback;
    }
}
