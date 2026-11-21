<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListAdminMoviesRequest;
use App\Http\Requests\StoreAdminMovieRequest;
use App\Http\Requests\UpdateAdminMovieRequest;
use App\Models\Movie;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminMovieController extends Controller
{
    private const OVERRIDABLE = [
        'title', 'overview', 'release_date', 'runtime_minutes', 'genres',
        'poster_url', 'backdrop_url', 'director', 'cast', 'trailer',
    ];

    public function index(ListAdminMoviesRequest $request): JsonResponse
    {
        $values = $request->validated();
        $movies = Movie::query()->orderBy('title')->orderBy('id');

        if (isset($values['search'])) {
            $movies->where('title', 'like', '%'.$values['search'].'%');
        }
        if (isset($values['source'])) {
            $movies->where('source', $values['source']);
        }
        if (($values['status'] ?? 'all') === 'active') {
            $movies->active();
        } elseif (($values['status'] ?? 'all') === 'deactivated') {
            $movies->whereNotNull('deactivated_at');
        }

        $paginator = $movies->paginate(
            (int) ($values['per_page'] ?? 20),
            ['*'],
            'page',
            (int) ($values['page'] ?? 1),
        )->withQueryString();

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Movie $movie): array => $this->summary($movie))->values(),
            'meta' => $this->meta($paginator),
            'links' => ['next' => $paginator->nextPageUrl(), 'previous' => $paginator->previousPageUrl()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(string $movieId): JsonResponse
    {
        return $this->response($this->movie($movieId));
    }

    public function store(StoreAdminMovieRequest $request): JsonResponse
    {
        $movie = DB::transaction(function () use ($request): Movie {
            $fields = $request->validated();

            return Movie::query()->create($fields + [
                'source' => 'local',
                'tmdb_id' => null,
                'vote_average' => null,
                'tmdb_synced_at' => null,
                'genres' => $fields['genres'] ?? [],
                'cast' => $fields['cast'] ?? [],
                'metadata_overrides' => [],
            ]);
        });

        return $this->response($movie, 201);
    }

    public function update(UpdateAdminMovieRequest $request, string $movieId): JsonResponse
    {
        $movie = DB::transaction(function () use ($request, $movieId): Movie {
            $current = Movie::query()->lockForUpdate()->findOrFail($this->key($movieId));
            $fields = $request->validated();
            $fields = array_intersect_key($fields, array_flip(self::OVERRIDABLE));

            if ($current->source === 'tmdb') {
                $overrides = $current->metadata_overrides ?? [];
                foreach (array_keys($fields) as $field) {
                    if (! in_array($field, $overrides, true)) {
                        $overrides[] = $field;
                    }
                }
                $current->metadata_overrides = $overrides;
            }

            $current->fill($fields);
            $current->save();

            return $current->refresh();
        });

        return $this->response($movie);
    }

    public function destroy(string $movieId): JsonResponse
    {
        $movie = DB::transaction(function () use ($movieId): Movie {
            $current = Movie::query()->lockForUpdate()->findOrFail($this->key($movieId));
            if ($current->deactivated_at === null) {
                $current->deactivated_at = now();
                $current->save();
            }

            return $current->refresh();
        });

        return $this->response($movie);
    }

    private function response(Movie $movie, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->detail($movie)], $status)
            ->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, mixed> */
    private function summary(Movie $movie): array
    {
        return [
            'local_movie_id' => (string) $movie->id,
            'title' => $movie->title,
            'source' => $movie->source,
            'tmdb_id' => $movie->tmdb_id,
            'poster_url' => $movie->poster_url,
            'release_date' => $movie->release_date?->format('Y-m-d'),
            'is_active' => $movie->deactivated_at === null,
            'deactivated_at' => $movie->deactivated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Movie $movie): array
    {
        return $this->summary($movie) + [
            'overview' => $movie->overview,
            'runtime_minutes' => $movie->runtime_minutes,
            'genres' => $movie->genres,
            'backdrop_url' => $movie->backdrop_url,
            'director' => $movie->director,
            'cast' => $movie->cast,
            'trailer' => $movie->trailer,
            'vote_average' => $movie->vote_average,
            'tmdb_synced_at' => $movie->tmdb_synced_at?->toISOString(),
            'metadata_overrides' => $movie->metadata_overrides,
        ];
    }

    /** @return array<string, int> */
    private function meta($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()];
    }

    private function movie(string $value): Movie
    {
        return Movie::query()->findOrFail($this->key($value));
    }

    private function key(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false || (string) $id !== $value, 404, 'Movie not found.');

        return $id;
    }
}
