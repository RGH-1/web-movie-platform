<?php

namespace App\Services;

use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminStatisticsService
{
    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        return [
            'kpis' => $this->kpis(),
            'most_watched_movies' => $this->mostWatchedMovies(),
            'highest_rated_movies' => $this->highestRatedMovies(),
            'popular_genres' => $this->popularGenres(),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    /** @return array<string, int> */
    private function kpis(): array
    {
        return [
            'total_users' => DB::table('users')->count(),
            'total_reviews' => DB::table('reviews')->count(),
            'visible_reviews' => DB::table('reviews')
                ->where('moderation_status', Review::STATUS_VISIBLE)
                ->count(),
            'total_movies' => DB::table('movies')->count(),
            'total_watched_entries' => DB::table('user_movie_statuses')
                ->whereNotNull('watched_at')
                ->count(),
            'total_watchlist_entries' => DB::table('user_movie_statuses')
                ->where('in_watchlist', true)
                ->count(),
        ];
    }

    /** @return list<array<string, int|string|null>> */
    private function mostWatchedMovies(): array
    {
        return DB::table('user_movie_statuses as statuses')
            ->join('movies as movies', 'movies.id', '=', 'statuses.movie_id')
            ->whereNotNull('statuses.watched_at')
            ->groupBy('movies.id', 'movies.title', 'movies.poster_url')
            ->selectRaw('movies.id as movie_id, movies.title, movies.poster_url, COUNT(DISTINCT statuses.user_id) as watch_count')
            ->orderByDesc('watch_count')
            ->orderBy('movies.id')
            ->limit(10)
            ->get()
            ->map(static fn ($movie): array => [
                'movie_id' => (string) $movie->movie_id,
                'title' => $movie->title,
                'poster_url' => $movie->poster_url,
                'watch_count' => (int) $movie->watch_count,
            ])->all();
    }

    /** @return list<array<string, int|string|float>> */
    private function highestRatedMovies(): array
    {
        return DB::table('reviews as reviews')
            ->join('movies as movies', 'movies.id', '=', 'reviews.movie_id')
            ->where('reviews.moderation_status', Review::STATUS_VISIBLE)
            ->groupBy('movies.id', 'movies.title')
            ->selectRaw('movies.id as movie_id, movies.title, AVG(reviews.rating) as average_rating, COUNT(*) as review_count')
            ->orderByDesc('average_rating')
            ->orderByDesc('review_count')
            ->orderBy('movies.id')
            ->limit(10)
            ->get()
            ->map(static fn ($movie): array => [
                'movie_id' => (string) $movie->movie_id,
                'title' => $movie->title,
                'average_rating' => round((float) $movie->average_rating, 2),
                'review_count' => (int) $movie->review_count,
            ])->all();
    }

    /** @return list<array{genre_id: int|null, genre_name: string, engagement_count: int}> */
    private function popularGenres(): array
    {
        $watched = DB::table('user_movie_statuses')
            ->whereNotNull('watched_at')
            ->select(['user_id', 'movie_id']);
        $reviewed = DB::table('reviews')
            ->where('moderation_status', Review::STATUS_VISIBLE)
            ->select(['user_id', 'movie_id']);
        $engagements = $watched->union($reviewed);

        // Aggregate in SQL first: PHP receives one row per engaged movie, not
        // the potentially much larger user/movie engagement history.
        $byMovie = DB::query()
            ->fromSub($engagements, 'engagements')
            ->selectRaw('movie_id, COUNT(*) as engagement_count')
            ->groupBy('movie_id');

        $movies = DB::table('movies')
            ->joinSub($byMovie, 'engaged_movies', 'engaged_movies.movie_id', '=', 'movies.id')
            ->select(['movies.id', 'movies.genres', 'engaged_movies.engagement_count'])
            ->orderBy('movies.id')
            ->get();

        $genres = [];
        foreach ($movies as $movie) {
            $metadata = json_decode((string) $movie->genres, true);
            if (! is_array($metadata)) {
                continue;
            }

            $seen = [];
            foreach ($metadata as $genre) {
                if (! is_array($genre) || ! is_string($genre['name'] ?? null)) {
                    continue;
                }

                $name = trim($genre['name']);
                if ($name === '') {
                    continue;
                }

                $genreId = filter_var($genre['tmdb_id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                $genreId = $genreId === false ? null : $genreId;
                $key = $genreId === null ? 'name:'.mb_strtolower($name) : 'id:'.$genreId;

                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                if (! isset($genres[$key])) {
                    $genres[$key] = [
                        'genre_id' => $genreId,
                        'genre_name' => $name,
                        'engagement_count' => 0,
                    ];
                }
                $genres[$key]['engagement_count'] += (int) $movie->engagement_count;
            }
        }

        $genres = array_values($genres);
        usort($genres, static fn (array $left, array $right): int => $right['engagement_count'] <=> $left['engagement_count']
            ?: strcasecmp($left['genre_name'], $right['genre_name'])
            ?: (($left['genre_id'] ?? PHP_INT_MAX) <=> ($right['genre_id'] ?? PHP_INT_MAX))
        );

        return array_slice($genres, 0, 10);
    }

    /** @return list<array<string, string>> */
    private function recentActivity(): array
    {
        $reviews = DB::table('reviews as reviews')
            ->join('users as users', 'users.id', '=', 'reviews.user_id')
            ->join('movies as movies', 'movies.id', '=', 'reviews.movie_id')
            ->selectRaw("'review_created' as event_type, reviews.id as event_id, users.id as user_id, users.name as user_name, movies.id as movie_id, movies.title as movie_title, reviews.created_at as occurred_at");
        $watches = DB::table('user_movie_statuses as statuses')
            ->join('users as users', 'users.id', '=', 'statuses.user_id')
            ->join('movies as movies', 'movies.id', '=', 'statuses.movie_id')
            ->whereNotNull('statuses.watched_at')
            ->selectRaw("'movie_watched' as event_type, statuses.id as event_id, users.id as user_id, users.name as user_name, movies.id as movie_id, movies.title as movie_title, statuses.watched_at as occurred_at");

        return DB::query()
            ->fromSub($reviews->unionAll($watches), 'activity')
            ->orderByDesc('occurred_at')
            ->orderBy('event_type')
            ->orderByDesc('event_id')
            ->limit(20)
            ->get()
            ->map(static fn ($event): array => [
                'event_type' => $event->event_type,
                'user_id' => (string) $event->user_id,
                'user_name' => $event->user_name,
                'movie_id' => (string) $event->movie_id,
                'movie_title' => $event->movie_title,
                'occurred_at' => Carbon::parse($event->occurred_at)->toISOString(),
            ])->all();
    }
}
