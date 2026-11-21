<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use App\Support\Ab150StressDataset;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDashboardMySqlStressTest extends TestCase
{
    private const URL = '/api/v1/admin/dashboard';

    public function test_seeded_mysql_dashboard_is_accurate_secure_bounded_and_read_only(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('AB#150 MySQL stress test is opt-in; use php artisan --env=ab150 test --configuration=phpunit.ab150-mysql.xml.');
        }

        Ab150StressDataset::assertIsolatedMySql();
        $counts = Ab150StressDataset::counts();
        $this->assertGreaterThanOrEqual(100, $counts['users'], 'Fixture must contain at least 100 users.');
        $this->assertGreaterThanOrEqual(40, $counts['movies'], 'Fixture must contain at least 40 movies.');
        $this->assertGreaterThanOrEqual(150, $counts['reviews'], 'Fixture must contain at least 150 reviews.');
        $this->assertGreaterThanOrEqual(200, $counts['statuses'], 'Fixture must contain at least 200 statuses.');

        $admin = User::query()->where('email', 'ab150-admin@example.test')->firstOrFail();
        $normalUser = User::query()->where('email', 'ab150-user-001@example.test')->firstOrFail();
        Http::preventStrayRequests();

        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAs($normalUser, 'web')->getJson(self::URL)->assertForbidden();

        $before = [
            'counts' => $counts,
            'rating_review' => DB::table('reviews')->where('movie_id', $this->movieId('AB150 Rating Test'))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $startedAt = hrtime(true);
        // Clear Sanctum's cached user before switching accounts.
        $this->app['auth']->guard('sanctum')->forgetUser();
        $response = $this->actingAs($admin, 'web')->getJson(self::URL)->assertOk();
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        $data = $response->json('data');
        // Capture this before independent verification queries run below.
        $dashboardQueryCount = $queries;

        $this->assertSame([
            'total_users' => $counts['users'],
            'total_reviews' => $counts['reviews'],
            'visible_reviews' => $counts['visible_reviews'],
            'total_movies' => $counts['movies'],
            'total_watched_entries' => $counts['watched_entries'],
            'total_watchlist_entries' => $counts['watchlist_entries'],
        ], $data['kpis'], 'KPI values must match independent database aggregates.');
        $this->assertLessThanOrEqual(12, $dashboardQueryCount, 'Dashboard should use a bounded query count and not N+1 queries.');

        $rating = $this->findMovie($data['highest_rated_movies'], 'AB150 Rating Test');
        $this->assertTrue(is_int($rating['average_rating']) || is_float($rating['average_rating']));
        $this->assertSame(4.0, (float) $rating['average_rating'], 'Hidden rating must be excluded from Scenario A average.');
        $this->assertSame(2, $rating['review_count'], 'Hidden rating must be excluded from Scenario A count.');
        $this->assertSame(3, DB::table('reviews')->where('movie_id', $this->movieId('AB150 Rating Test'))->count(), 'Scenario A must store all three reviews.');

        $watched = $this->findMovie($data['most_watched_movies'], 'AB150 Watched Test');
        $this->assertSame(2, $watched['watch_count'], 'Watchlist-only status must not count as watched.');
        $this->assertSame(2, DB::table('user_movie_statuses')->where('movie_id', $this->movieId('AB150 Watched Test'))->whereNotNull('watched_at')->distinct('user_id')->count('user_id'), 'Scenario B independently counts two watched users.');

        $genreMovieId = $this->movieId('AB150 Genre Dedup Test');
        $genreEngagements = DB::query()->fromSub(
            DB::table('user_movie_statuses')->where('movie_id', $genreMovieId)->whereNotNull('watched_at')->select(['user_id', 'movie_id'])
                ->union(DB::table('reviews')->where('movie_id', $genreMovieId)->where('moderation_status', 'visible')->select(['user_id', 'movie_id'])),
            'engagements',
        )->count();
        $this->assertSame(3, $genreEngagements, 'Scenario C must deduplicate a user who both watched and reviewed.');
        $genres = collect($data['popular_genres'])->keyBy('genre_id');
        $this->assertGreaterThanOrEqual(3, $genres->get(18)['engagement_count'] ?? 0, 'Drama must include Scenario C engagements.');
        $this->assertGreaterThanOrEqual(3, $genres->get(878)['engagement_count'] ?? 0, 'Science Fiction must include Scenario C engagements.');

        $ties = collect($data['highest_rated_movies'])->filter(fn (array $movie): bool => str_starts_with($movie['title'], 'AB150 Tie'))->values();
        $this->assertSame('AB150 Tie Count Three', $ties[0]['title'], 'Rating ties must use review count descending.');
        $this->assertSame(3, $ties[0]['review_count']);
        $this->assertLessThan(
            $this->movieId('AB150 Tie Canonical Two'),
            $this->movieId('AB150 Tie Canonical One'),
            'Fixture ordering expects Canonical One to have the lower id.',
        );
        $this->assertSame(['AB150 Tie Canonical One', 'AB150 Tie Canonical Two'], $ties->pluck('title')->filter(fn (string $title): bool => str_starts_with($title, 'AB150 Tie Canonical'))->values()->all(), 'Complete ties must use movie id ascending.');

        $this->assertCount(10, $data['most_watched_movies'], 'Most-watched ranking must be capped at ten.');
        $this->assertCount(10, $data['highest_rated_movies'], 'Highest-rated ranking must be capped at ten.');
        $this->assertLessThanOrEqual(
            10,
            count($data['popular_genres']),
            'Genre ranking must return at most ten genres.'
        );
        $this->assertCount(20, $data['recent_activity'], 'Recent activity must be capped at twenty.');
        $this->assertSortedActivity($data['recent_activity']);
        $this->assertSame(20, collect($data['recent_activity'])->map(fn (array $event): string => implode(':', [$event['event_type'], $event['user_id'], $event['movie_id'], $event['occurred_at']]))->unique()->count(), 'Recent activity must not contain duplicate events.');
        $equalTimestamp = Carbon::parse('2026-03-01 11:28:00')->toISOString();
        $expectedTiedWatches = DB::table('user_movie_statuses as statuses')->join('movies', 'movies.id', '=', 'statuses.movie_id')
            ->where('statuses.watched_at', '2026-03-01 11:28:00')->orderByDesc('statuses.id')->pluck('movies.title')->all();
        $actualTiedWatches = collect($data['recent_activity'])->filter(fn (array $event): bool => $event['occurred_at'] === $equalTimestamp && $event['event_type'] === 'movie_watched')->pluck('movie_title')->all();
        $this->assertSame($expectedTiedWatches, $actualTiedWatches, 'Equal timestamps must use descending event id after event type.');
        $this->assertFalse(collect($data['recent_activity'])->contains(fn (array $event): bool => $event['movie_title'] === 'AB150 Watched Test' && $event['event_type'] === 'movie_watched' && $event['user_id'] === (string) User::query()->where('email', 'ab150-user-003@example.test')->value('id')), 'Watchlist-only fixture must not invent an event.');
        $this->assertArrayNotHasKey('email', $data['recent_activity'][0]);
        $this->assertArrayNotHasKey('review_text', $data['recent_activity'][0]);

        // Scenario A transition: verify the changed aggregate, then restore the inspectable baseline.
        $hidden = Review::query()->where('movie_id', $this->movieId('AB150 Rating Test'))->where('rating', 1)->firstOrFail();
        try {
            DB::table('reviews')->where('id', $hidden->id)->update(['moderation_status' => Review::STATUS_VISIBLE]);
            $changed = $this->actingAs($admin, 'web')->getJson(self::URL)->json('data.highest_rated_movies');
            $changedRating = $this->findMovie($changed, 'AB150 Rating Test');
            $this->assertSame(
                3.0,
                (float) $changedRating['average_rating'],
                'Restoring the hidden rating must change Scenario A average to 3.00.'
            );
            $this->assertSame(3, $changedRating['review_count'], 'Restoring the hidden rating must change Scenario A count to three.');
        } finally {
            DB::table('reviews')->where('id', $hidden->id)->update(['moderation_status' => Review::STATUS_HIDDEN]);
        }

        $this->assertSame($before['counts'], Ab150StressDataset::counts(), 'Dashboard reads and temporary Scenario A mutation must leave fixture counts unchanged.');
        $this->assertSame($before['rating_review'], DB::table('reviews')->where('movie_id', $this->movieId('AB150 Rating Test'))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), 'Scenario A review contents must be restored exactly.');
        fwrite(STDERR, sprintf("AB150 MySQL dashboard request: %.2f ms, %d queries (verification queries excluded)\n", $elapsedMilliseconds, $dashboardQueryCount));
    }

    public function test_genre_ranking_is_limited_to_ten_with_more_than_ten_engaged_genres(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('AB#150 MySQL stress test is opt-in; use php artisan --env=ab150 test --configuration=phpunit.ab150-mysql.xml.');
        }

        Ab150StressDataset::assertIsolatedMySql();
        Http::preventStrayRequests();
        $admin = User::query()->where('email', 'ab150-admin@example.test')->firstOrFail();
        $movieId = $this->movieId('AB150 Genre Dedup Test');
        $originalGenres = DB::table('movies')->where('id', $movieId)->value('genres');
        $beforeCounts = Ab150StressDataset::counts();
        $overflowGenres = array_map(static fn (int $index): array => [
            'tmdb_id' => 9900 + $index,
            'name' => sprintf('AB150 Overflow Genre %02d', $index),
        ], range(1, 12));

        try {
            DB::table('movies')->where('id', $movieId)->update([
                'genres' => json_encode($overflowGenres, JSON_THROW_ON_ERROR),
            ]);

            $expectedGenres = $this->expectedGenreRanking();
            $this->assertGreaterThan(10, count($expectedGenres), 'Fixture must have more than ten engaged genres.');

            $this->app['auth']->guard('sanctum')->forgetUser();
            $actualGenres = $this->actingAs($admin, 'web')->getJson(self::URL)
                ->assertOk()
                ->json('data.popular_genres');

            $this->assertCount(10, $actualGenres, 'Genre ranking must return exactly ten rows when more than ten genres are engaged.');
            $this->assertSame(array_slice($expectedGenres, 0, 10), $actualGenres, 'Genre ranking must use engagement count, genre name, and genre ID deterministic ordering.');
            $this->assertNotContains($expectedGenres[10]['genre_id'], array_column($actualGenres, 'genre_id'), 'The independently calculated eleventh genre must be excluded.');
        } finally {
            DB::table('movies')->where('id', $movieId)->update(['genres' => $originalGenres]);
            $this->assertSame($originalGenres, DB::table('movies')->where('id', $movieId)->value('genres'), 'Original raw genre metadata must be restored.');
            $this->assertSame($beforeCounts, Ab150StressDataset::counts(), 'Genre-overflow verification must not change fixture record counts.');
        }
    }

    private function movieId(string $title): int
    {
        return (int) DB::table('movies')->where('title', $title)->value('id');
    }

    /** @return list<array{genre_id: int|null, genre_name: string, engagement_count: int}> */
    private function expectedGenreRanking(): array
    {
        $watched = DB::table('user_movie_statuses')
            ->whereNotNull('watched_at')
            ->select(['user_id', 'movie_id']);
        $reviewed = DB::table('reviews')
            ->where('moderation_status', Review::STATUS_VISIBLE)
            ->select(['user_id', 'movie_id']);
        $engagements = $watched->union($reviewed);
        $byMovie = DB::query()
            ->fromSub($engagements, 'engagements')
            ->selectRaw('movie_id, COUNT(*) as engagement_count')
            ->groupBy('movie_id');
        $movies = DB::table('movies')
            ->joinSub($byMovie, 'engaged_movies', 'engaged_movies.movie_id', '=', 'movies.id')
            ->select(['movies.genres', 'engaged_movies.engagement_count'])
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

                $genreId = filter_var($genre['tmdb_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $genreId = $genreId === false ? null : $genreId;
                $key = $genreId === null ? 'name:'.mb_strtolower($name) : 'id:'.$genreId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $genres[$key] ??= ['genre_id' => $genreId, 'genre_name' => $name, 'engagement_count' => 0];
                $genres[$key]['engagement_count'] += (int) $movie->engagement_count;
            }
        }

        $genres = array_values($genres);
        usort($genres, static fn (array $left, array $right): int => $right['engagement_count'] <=> $left['engagement_count']
            ?: strcasecmp($left['genre_name'], $right['genre_name'])
            ?: (($left['genre_id'] ?? PHP_INT_MAX) <=> ($right['genre_id'] ?? PHP_INT_MAX)));

        return $genres;
    }

    /** @param list<array<string, mixed>> $movies @return array<string, mixed> */
    private function findMovie(array $movies, string $title): array
    {
        foreach ($movies as $movie) {
            if ($movie['title'] === $title) {
                return $movie;
            }
        }
        $this->fail("Expected {$title} in dashboard ranking.");
    }

    /** @param list<array<string, mixed>> $activity */
    private function assertSortedActivity(array $activity): void
    {
        $previous = null;
        foreach ($activity as $event) {
            $current = $event['occurred_at'].'|'.$event['event_type'];
            if ($previous !== null) {
                $this->assertGreaterThanOrEqual($current, $previous, 'Recent activity must be newest first with deterministic event-type ties.');
            }
            $previous = $current;
        }
    }
}
