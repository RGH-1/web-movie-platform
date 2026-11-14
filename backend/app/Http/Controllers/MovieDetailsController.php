<?php

namespace App\Http\Controllers;

use App\Exceptions\TmdbRequestException;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use App\Services\MovieCatalogService;
use Illuminate\Http\Request;
use App\Models\Movie;
use UnexpectedValueException;

class MovieDetailsController extends Controller
{
    public function show(Request $request, string $tmdbId, TmdbService $tmdb, MovieCatalogService $catalog): JsonResponse
    {
        if (
            !preg_match('/^[1-9][0-9]*$/D', $tmdbId) ||
            strlen($tmdbId) > 10 ||
            (float) $tmdbId > 2147483647
        ) {
            return response()->json([
                'message' => 'Invalid movie identifier.',
                'code' => 'INVALID_MOVIE_ID',
            ], 422);
        }

        try {
            $movie = $tmdb->getMovieDetails((int) $tmdbId);

            if (mb_strlen($movie['title']) > 255) {
                throw new UnexpectedValueException('Invalid normalized movie title.');
            }
        } catch (TmdbRequestException $exception) {
            if ($exception->upstreamStatus === 404) {
                return response()->json([
                    'message' => 'Movie not found.',
                    'code' => 'MOVIE_NOT_FOUND',
                ], 404);
            }

            if ($exception->upstreamStatus === 429) {
                $retryAfter = filter_var(
                    $exception->retryAfter,
                    FILTER_VALIDATE_INT
                );

                $retryAfter = $retryAfter !== false && $retryAfter > 0
                    ? $retryAfter
                    : 30;

                return response()->json([
                    'message' => 'Movie details are temporarily rate limited.',
                    'code' => 'MOVIE_DETAILS_RATE_LIMITED',
                ], 503)->header('Retry-After', (string) $retryAfter);
            }

            report($exception);

            return response()->json([
                'message' => 'Movie details are temporarily unavailable.',
                'code' => 'MOVIE_DETAILS_UNAVAILABLE',
            ], 502);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Movie details are temporarily unavailable.',
                'code' => 'MOVIE_DETAILS_UNAVAILABLE',
            ], 502);
        }

        $local = Movie::query()->where('tmdb_id', $movie['tmdb_id'])->first();
        $deactivated = $local?->deactivated_at !== null;
        $localMovieId = null;

        if ($local !== null && ! $deactivated) {
            $localMovieId = (string) $local->getKey();
            $stored = $catalog->details($local);

            foreach (MovieCatalogService::METADATA as $field) {
                if (in_array($field, $local->metadata_overrides, true)) {
                    $movie[$field] = $stored[$field];
                }
            }
        }

        $viewerReview = $localMovieId === null
            ? null
            : $request->user()->reviews()
                ->where('movie_id', $localMovieId)
                ->first(['id', 'rating', 'review_text']);

        $movie['source'] = 'tmdb';
        $movie['local_movie_id'] = $localMovieId;
        $movie['catalog_deactivated'] = $deactivated;
        $movie['viewer_review'] = $viewerReview === null ? null : [
            'id' => (string) $viewerReview->getKey(),
            'rating' => $viewerReview->rating,
            'review_text' => $viewerReview->review_text,
        ];

        return response()
            ->json(['data' => $movie])
            ->header('Cache-Control', 'private, no-store');
            }
}
