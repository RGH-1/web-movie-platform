<?php

namespace App\Http\Controllers;

use App\Exceptions\TmdbRequestException;
use App\Http\Requests\ResolveTmdbMovieRequest;
use App\Services\MovieCatalogService;
use Illuminate\Http\JsonResponse;
use UnexpectedValueException;

class ResolveTmdbMovieController extends Controller
{
    public function __invoke(
        ResolveTmdbMovieRequest $request,
        MovieCatalogService $catalog,
    ): JsonResponse {
        try {
            $movie = $catalog->resolveTmdbId((int) $request->validated('tmdbId'));
        } catch (TmdbRequestException $exception) {
            if ($exception->upstreamStatus === 404) {
                return $this->notFound();
            }

            if ($exception->upstreamStatus === 429) {
                $seconds = filter_var($exception->retryAfter, FILTER_VALIDATE_INT);
                $delay = $seconds !== false && $seconds > 0 ? $seconds : 30;

                return response()->json([
                    'message' => 'Movie import is temporarily rate limited.',
                    'code' => 'MOVIE_RESOLVE_RATE_LIMITED',
                ], 503)->header('Retry-After', (string) $delay);
            }

            report($exception);

            return $this->unavailable();
        } catch (UnexpectedValueException $exception) {
            report($exception);

            return $this->unavailable();
        }

        if ($movie->deactivated_at !== null) {
            return $this->notFound();
        }

        return response()->json([
            'data' => [
                'local_movie_id' => (string) $movie->getKey(),
                'tmdb_id' => $movie->tmdb_id,
                'source' => $movie->source,
            ],
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Movie not found or unavailable.',
            'code' => 'MOVIE_NOT_FOUND',
        ], 404);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'Movie import is temporarily unavailable.',
            'code' => 'MOVIE_RESOLVE_UNAVAILABLE',
        ], 502);
    }
}