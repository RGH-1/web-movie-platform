<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\MovieList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MovieListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->listing($request, $request->user()->movieLists());
    }

    public function publicIndex(Request $request): JsonResponse
    {
        return $this->listing($request, MovieList::where('is_public', true));
    }

    private function listing(Request $request, $query): JsonResponse
    {
        $options = $this->pagination($request);
        $lists = $query->with('user:id,name')->withCount('movies')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($options['per_page'] ?? 12, ['*'], 'page', $options['page'] ?? 1);

        return response()->json([
            'data' => $lists->getCollection()->map(fn (MovieList $list) => $this->summary($list)),
            'meta' => $this->meta($lists),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $listId): JsonResponse
    {
        $list = MovieList::findOrFail($this->key($listId));
        abort_unless($list->is_public || (string) $list->user_id === (string) $request->user()->id, 404);
        $options = $this->pagination($request);
        $movies = $list->movies()->orderByDesc('movie_list_items.created_at')->orderByDesc('movies.id')
            ->paginate($options['per_page'] ?? 12, ['movies.*'], 'page', $options['page'] ?? 1);

        return response()->json([
            'data' => $this->summary($list->load('user:id,name')->loadCount('movies')) + [
                'movies' => $movies->getCollection()->map(fn (Movie $movie) => [
                    'local_movie_id' => $movie->id,
                    'tmdb_id' => $movie->tmdb_id,
                    'source' => $movie->source,
                    'title' => $movie->title,
                    'overview' => $movie->overview,
                    'poster_url' => $movie->poster_url,
                    'release_date' => $movie->release_date?->format('Y-m-d'),
                    'vote_average' => $movie->vote_average,
                    'is_active' => $movie->deactivated_at === null,
                ]),
            ],
            'meta' => $this->meta($movies),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $list = $request->user()->movieLists()->create($this->fields($request));

        return $this->saved($list, 201);
    }

    public function update(Request $request, string $listId): JsonResponse
    {
        $list = DB::transaction(function () use ($request, $listId): MovieList {
            $list = $this->owned($request, $listId);
            $list->update($this->fields($request));

            return $list;
        });

        return $this->saved($list);
    }

    public function destroy(Request $request, string $listId): Response
    {
        DB::transaction(fn () => $this->owned($request, $listId)->delete());

        return response()->noContent();
    }

    public function addMovie(Request $request, string $listId): JsonResponse
    {
        $list = DB::transaction(function () use ($request, $listId): MovieList {
            $list = $this->owned($request, $listId);
            $values = $request->validate([
                'movie_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            ]);
            $movie = Movie::active()->lockForUpdate()->findOrFail($this->key($values['movie_id']));
            // Locking the list serializes membership writes and makes repeated adds idempotent.
            $list->movies()->syncWithoutDetaching([$movie->id]);

            return $list;
        });

        return $this->saved($list);
    }

    public function removeMovie(Request $request, string $listId, string $movieId): Response
    {
        DB::transaction(function () use ($request, $listId, $movieId): void {
            $list = $this->owned($request, $listId);
            $list->movies()->detach($this->key($movieId));
        });

        return response()->noContent();
    }

    private function owned(Request $request, string $listId): MovieList
    {
        $list = MovieList::lockForUpdate()->findOrFail($this->key($listId));
        abort_unless((string) $list->user_id === (string) $request->user()->id, 404);

        return $list;
    }

    private function fields(Request $request): array
    {
        return $request->validate([
            'name' => [$request->isMethod('post') ? 'required' : 'sometimes', 'filled', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_public' => ['sometimes', 'required', 'boolean'],
            'user_id' => ['prohibited'],
            'id' => ['prohibited'],
        ]);
    }

    private function pagination(Request $request): array
    {
        return validator($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ])->validate();
    }

    private function key(string $value): string
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,18}$/D', $value) &&
            (strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0), 404);

        return $value;
    }

    private function saved(MovieList $list, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->summary($list->load('user:id,name')->loadCount('movies'))], $status)
            ->header('Cache-Control', 'private, no-store');
    }

    private function summary(MovieList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'is_public' => $list->is_public,
            'author' => ['id' => (string) $list->user_id, 'name' => $list->user->name],
            'movie_count' => $list->movies_count,
            'created_at' => $list->created_at?->toIso8601String(),
            'updated_at' => $list->updated_at?->toIso8601String(),
        ];
    }

    private function meta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
