<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\MovieCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalMovieDetailsController extends Controller
{
    public function show(Request $request, string $movieId, MovieCatalogService $catalog): JsonResponse
    {
        if (!preg_match('/^[1-9][0-9]*$/D', $movieId) || strlen($movieId) > 19 ||
            (strlen($movieId) === 19 && strcmp($movieId, '9223372036854775807') > 0)) {
            return response()->json(['message' => 'Invalid movie identifier.', 'code' => 'INVALID_MOVIE_ID'], 422);
        }
        $movie = Movie::active()->find($movieId);
        if ($movie === null) {
            return response()->json(['message' => 'Movie not found.', 'code' => 'MOVIE_NOT_FOUND'], 404);
        }
        $data = $catalog->details($movie);
        $review = $request->user()->reviews()->where('movie_id', $movie->id)->first();
        $data['viewer_review'] = $review === null ? null : [
            'id' => (string) $review->id, 'rating' => $review->rating, 'review_text' => $review->review_text,
        ];

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
