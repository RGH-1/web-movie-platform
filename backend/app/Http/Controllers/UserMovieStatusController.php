<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\UserMovieStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserMovieStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'filter' => ['required', Rule::in(['watchlist', 'watched'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid query parameters.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $filter = $validated['filter'];
        $perPage = (int) ($validated['per_page'] ?? 12);

        $query = $request->user()
            ->movieStatuses()
            ->with('movie');

        if ($filter === 'watchlist') {
            $query->where('in_watchlist', true)
                ->orderByDesc('updated_at')
                ->orderByDesc('id');
        } else {
            $query->whereNotNull('watched_at')
                ->orderByDesc('watched_at')
                ->orderByDesc('id');
        }

        $statuses = $query->paginate($perPage);

        $movies = $statuses->getCollection()
            ->map(function (UserMovieStatus $status): array {
                $movie = $status->movie;

                return [
                    'movie_id' => (string) $status->movie_id,
                    'in_watchlist' => $status->in_watchlist,
                    'is_watched' => $status->isWatched(),
                    'watched_at' => $status->watched_at?->toIso8601String(),
                    'movie' => [
                        'id' => (string) $movie->id,
                        'tmdb_id' => $movie->tmdb_id,
                        'source' => $movie->source,
                        'title' => $movie->title,
                        'overview' => $movie->overview,
                        'poster_url' => $movie->poster_url,
                        'backdrop_url' => $movie->backdrop_url,
                        'release_date' => $movie->release_date,
                        'vote_average' => $movie->vote_average === null
                            ? null
                            : (float) $movie->vote_average,
                        'genres' => $movie->genres,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'data' => $movies,
            'meta' => [
                'filter' => $filter,
                'current_page' => $statuses->currentPage(),
                'last_page' => $statuses->lastPage(),
                'per_page' => $statuses->perPage(),
                'total' => $statuses->total(),
            ],
            'links' => [
                'next' => $statuses->nextPageUrl(),
                'previous' => $statuses->previousPageUrl(),
            ],
        ]);
    }

    public function show(Request $request, string $movieId): JsonResponse
    {
        $validator = Validator::make(
            ['movie_id' => $movieId],
            [
                'movie_id' => ['required', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid movie ID.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $movie = Movie::find($movieId);

        if (! $movie) {
            return response()->json([
                'message' => 'Movie not found.',
            ], 404);
        }

        $status = $request->user()
            ->movieStatuses()
            ->where('movie_id', $movie->id)
            ->first();

        return response()->json([
            'data' => [
                'movie_id' => (string) $movie->id,
                'in_watchlist' => $status?->in_watchlist ?? false,
                'is_watched' => $status?->isWatched() ?? false,
                'watched_at' => $status?->watched_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, string $movieId): JsonResponse
    {
        $movieValidator = Validator::make(
            ['movie_id' => $movieId],
            ['movie_id' => ['required', 'integer', 'min:1']]
        );

        if ($movieValidator->fails()) {
            return response()->json([
                'message' => 'Invalid movie ID.',
                'errors' => $movieValidator->errors(),
            ], 422);
        }

        $movie = Movie::find($movieId);

        if (! $movie) {
            return response()->json([
                'message' => 'Movie not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'in_watchlist' => ['sometimes', 'required', 'boolean'],
            'is_watched' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid status fields.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        if (empty($validated)) {
            return response()->json([
                'message' => 'At least one status field is required.',
                'errors' => [
                    'status' => [
                        'Provide in_watchlist or is_watched.',
                    ],
                ],
            ], 422);
        }

        $data = DB::transaction(function () use ($request, $movie, $validated): array {
            $user = $request->user();

            // Serialize lifecycle and association changes against the same movie row.
            $movie = Movie::query()->lockForUpdate()->findOrFail($movie->id);

            $user->newQuery()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $status = $user->movieStatuses()
                ->lockForUpdate()
                ->where('movie_id', $movie->id)
                ->first() ?? new UserMovieStatus([
                    'user_id' => $user->id,
                    'movie_id' => $movie->id,
                ]);

            $addsWatchlist = ($validated['in_watchlist'] ?? false) && ! $status->in_watchlist;
            $addsWatched = ($validated['is_watched'] ?? false) && $status->watched_at === null;
            if ($movie->deactivated_at !== null && ($addsWatchlist || $addsWatched)) {
                abort(409, 'Movie is no longer available for new associations.');
            }

            if (array_key_exists('in_watchlist', $validated)) {
                $status->in_watchlist = $validated['in_watchlist'];
            }

            if (array_key_exists('is_watched', $validated)) {
                if ($validated['is_watched']) {
                    if ($status->watched_at === null) {
                        $status->watched_at = now();
                    }
                } else {
                    $status->watched_at = null;
                }
            }

            if (! $status->in_watchlist && $status->watched_at === null) {
                if ($status->exists) {
                    $status->delete();
                }
            } elseif ($status->isDirty()) {
                $status->save();
            }

            return [
                'movie_id' => (string) $movie->id,
                'in_watchlist' => (bool) $status->in_watchlist,
                'is_watched' => $status->isWatched(),
                'watched_at' => $status->watched_at?->toIso8601String(),
            ];
        }, 3);

        return response()->json([
            'message' => 'Movie status updated successfully.',
            'data' => $data,
        ]);
    }
}
