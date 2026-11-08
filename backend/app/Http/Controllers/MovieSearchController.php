<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovieSearchRequest;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use App\Exceptions\TmdbRequestException;

class MovieSearchController extends Controller
{
    public function search(
        MovieSearchRequest $request,
        TmdbService $tmdb
    ): JsonResponse {
        $validated = $request->validated();

        $query = trim($validated['query']);
        $page = (int) ($validated['page'] ?? 1);

        try {
            $response = $tmdb->get('/search/movie', [
                'query' => $query,
                'page' => $page,
                'language' => 'en-US',
                'include_adult' => false,
            ]);

            $payload = $response->json();

            if (
                !is_array($payload) ||
                !isset($payload['results']) ||
                !is_array($payload['results']) ||
                !isset($payload['total_pages'], $payload['total_results']) ||
                !is_int($payload['total_pages']) ||
                !is_int($payload['total_results'])
            ) {
                throw new RuntimeException('Invalid TMDB response.');
            }

            $data = [];

            foreach ($payload['results'] as $movie) {
                if (
                    !is_array($movie) ||
                    !isset($movie['id'], $movie['title']) ||
                    !is_int($movie['id']) ||
                    !is_string($movie['title'])
                ) {
                    throw new RuntimeException('Invalid movie data from TMDB.');
                }

                $posterPath = $movie['poster_path'] ?? null;

                $data[] = [
                    'tmdb_id' => $movie['id'],
                    'title' => $movie['title'],
                    'overview' => $movie['overview'] ?? null,
                    'release_date' => $movie['release_date'] ?? null,
                    'poster_url' => is_string($posterPath) && $posterPath !== ''
                        ? 'https://image.tmdb.org/t/p/w342' . $posterPath
                        : null,
                    'vote_average' => ($movie['vote_count'] ?? 0) > 0
                        ? ($movie['vote_average'] ?? null)
                        : null,
                ];
            }

            return response()->json([
                'data' => $data,
                'meta' => [
                    'page' => $page,
                    'total_pages' => min(500, $payload['total_pages']),
                    'total_results' => $payload['total_results'],
                ],
            ], 200);

        } catch (RuntimeException $exception) {
            if (
                $exception instanceof TmdbRequestException
                && $exception->upstreamStatus === 429
            ) {
                $retryAfter = filter_var(
                    $exception->retryAfter,
                    FILTER_VALIDATE_INT
                );

                $retryAfter = $retryAfter !== false && $retryAfter > 0
                    ? $retryAfter
                    : 30;

                return response()->json([
                    'message' => 'Movie search is temporarily rate limited.',
                    'code' => 'MOVIE_SEARCH_RATE_LIMITED',
                ], 503)->header('Retry-After', (string) $retryAfter);
            }

            report($exception);

            return response()->json([
                'message' => 'Movie search is temporarily unavailable.',
                'code' => 'MOVIE_SEARCH_UNAVAILABLE',
            ], 502);
        }
    }
}
