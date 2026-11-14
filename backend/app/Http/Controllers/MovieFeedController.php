<?php

namespace App\Http\Controllers;

use App\Exceptions\TmdbRequestException;
use App\Http\Requests\MovieDiscoveryRequest;
use App\Http\Requests\MovieFeedRequest;
use App\Services\MovieCatalogService;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class MovieFeedController extends Controller
{
    public function popular(MovieFeedRequest $request, TmdbService $tmdb): JsonResponse
    {
        return $this->respond($request, $tmdb->getPopularMovies(...));
    }

    public function upcoming(MovieFeedRequest $request, TmdbService $tmdb): JsonResponse
    {
        return $this->respond($request, $tmdb->getUpcomingMovies(...));
    }

    public function discover(MovieDiscoveryRequest $request, TmdbService $tmdb): JsonResponse
    {
        $filters = $request->validated();

        return $this->respond($request, function (int $page) use ($filters, $tmdb): array {
            if (isset($filters['genre_id']) && ! in_array((int) $filters['genre_id'], array_column($tmdb->getMovieGenres(), 'id'), true)) {
                throw ValidationException::withMessages([
                    'genre_id' => ['The selected genre is not a supported movie genre.'],
                ]);
            }

            return $tmdb->discoverMovies($filters);
        });
    }

    public function genres(MovieFeedRequest $request, TmdbService $tmdb): JsonResponse
    {
        return $this->respond($request, fn (int $page): array => ['data' => $tmdb->getMovieGenres()], false);
    }

    private function respond(MovieFeedRequest $request, callable $load, bool $movieCards = true): JsonResponse
    {
        $page = (int) $request->validated('page', 1);

        try {
            $feed = $load($page);
        } catch (TmdbRequestException|UnexpectedValueException $exception) {
            if ($exception instanceof TmdbRequestException && $exception->upstreamStatus === 429) {
                $seconds = filter_var($exception->retryAfter, FILTER_VALIDATE_INT);
                $retryAfter = $seconds !== false && $seconds > 0 ? $seconds : 30;

                return response()->json([
                    'message' => 'Movie listings are temporarily rate limited.',
                    'code' => 'MOVIE_FEED_RATE_LIMITED',
                ], 503)->header('Retry-After', (string) $retryAfter);
            }

            report($exception);

            return response()->json([
                'message' => 'Movie listings are temporarily unavailable.',
                'code' => 'MOVIE_FEED_UNAVAILABLE',
            ], 502);
        }

        if ($movieCards) {
            $feed['data'] = app(MovieCatalogService::class)->attachLocalIds($feed['data']);
        }

        return response()->json($feed);
    }
}
