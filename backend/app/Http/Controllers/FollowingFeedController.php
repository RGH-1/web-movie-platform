<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\MovieList;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FollowingFeedController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $options = validator($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ])->validate();

        $followed = $request->user()->following()->getQuery()
            ->select('users.id');

        $ratingOnly = Review::RATING_ONLY_SQL;

        $reviews = Review::query()
            ->sharedActivity()
            ->joinSub(
                Movie::active()->select('id', 'title', 'poster_url'),
                'movies',
                'movies.id',
                '=',
                'reviews.movie_id',
            )
            ->whereIn('reviews.user_id', $followed)
            ->where('reviews.moderation_status', Review::STATUS_VISIBLE)
            ->selectRaw("
                CASE WHEN {$ratingOnly}
                    THEN 'rating' ELSE 'review' END AS type,
                reviews.id AS target_id,
                reviews.user_id,
                reviews.created_at AS activity_at,
                movies.title AS title,
                movies.poster_url,
                reviews.movie_id,
                reviews.rating
            ");

        $lists = MovieList::query()
            ->sharedActivity()
            ->whereIn('movie_lists.user_id', $followed)
            ->selectRaw("
                'public_list' AS type,
                movie_lists.id AS target_id,
                movie_lists.user_id,
                COALESCE(movie_lists.published_at, movie_lists.created_at) AS activity_at,
                movie_lists.name AS title,
                NULL AS poster_url,
                NULL AS movie_id,
                NULL AS rating
            ");

        $page = DB::query()
            ->fromSub($reviews->unionAll($lists), 'activities')
            ->join('users', 'users.id', '=', 'activities.user_id')
            ->select('activities.*', 'users.name AS author_name')
            ->orderByDesc('activity_at')
            ->orderBy('type')
            ->orderByDesc('target_id')
            ->paginate(
                $options['per_page'] ?? 12,
                page: $options['page'] ?? 1,
            );

        return response()->json([
            'data' => $page->getCollection()->map(
                fn (object $activity): array => [
                    'type' => $activity->type,
                    'author' => [
                        'id' => (string) $activity->user_id,
                        'name' => $activity->author_name,
                    ],
                    'target_id' => (string) $activity->target_id,
                    'timestamp' => Carbon::parse($activity->activity_at)
                        ->toIso8601String(),
                    'metadata' => $activity->type === 'public_list'
                        ? ['name' => $activity->title]
                        : [
                            'movie_id' => (string) $activity->movie_id,
                            'title' => $activity->title,
                            'poster_url' => $activity->poster_url,
                            'rating' => (int) $activity->rating,
                        ],
                ],
            ),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}