<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogMovieController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $values = validator($request->query(), [
            'search' => ['sometimes', 'required', 'string', 'max:255'],
            'source' => ['sometimes', 'required', Rule::in(['local', 'tmdb'])],
            'page' => ['sometimes', 'required', 'integer', 'between:1,2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'between:1,50'],
        ])->validate();
        $movies = Movie::active()->orderBy('title')->orderBy('id');
        if (isset($values['search'])) {
            $movies->where('title', 'like', '%'.$values['search'].'%');
        }
        if (isset($values['source'])) {
            $movies->where('source', $values['source']);
        }
        $paginator = $movies->paginate((int) ($values['per_page'] ?? 20), ['*'], 'page', (int) ($values['page'] ?? 1));

        return response()->json([
            'data' => $paginator->getCollection()->map(static fn (Movie $movie): array => [
                'local_movie_id' => (string) $movie->id,
                'title' => $movie->title,
                'source' => $movie->source,
                'tmdb_id' => $movie->tmdb_id,
                'poster_url' => $movie->poster_url,
                'release_date' => $movie->release_date?->format('Y-m-d'),
            ])->values(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ])->header('Cache-Control', 'private, no-store');
    }
}
