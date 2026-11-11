<?php

namespace App\Http\Controllers;

use App\Exceptions\TmdbRequestException;
use App\Http\Requests\MovieFeedRequest;
use App\Models\Movie;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
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

    private function respond(MovieFeedRequest $request, callable $load): JsonResponse
    {
        $page = (int) $request->validated('page', 1);

        try {
            $feed = $load($page);
        } catch (TmdbRequestException | UnexpectedValueException $exception) {
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

        $feed['data'] = $this->attachLocalIds($feed['data']);

        return response()->json($feed);
    }

    private function attachLocalIds(array $movies): array
    {
        if ($movies === []) {
            return [];
        }

        $localIds = Movie::query()
            ->active()
            ->whereIn('tmdb_id', array_column($movies, 'tmdb_id'))
            ->pluck('id', 'tmdb_id');

        foreach ($movies as $index => $movie) {
            $movies[$index]['local_movie_id'] = $localIds->get($movie['tmdb_id']);
        }

        return $movies;
    }
}