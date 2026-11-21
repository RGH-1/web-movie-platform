<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use App\Models\UserMovieStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/dashboard';

    public function test_guests_and_non_administrators_are_rejected(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson(self::URL)
            ->assertForbidden();
    }

    public function test_administrator_receives_empty_dashboard_without_sensitive_data(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web')->getJson(self::URL)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.kpis', [
                'total_users' => 1,
                'total_reviews' => 0,
                'visible_reviews' => 0,
                'total_movies' => 0,
                'total_watched_entries' => 0,
                'total_watchlist_entries' => 0,
            ])
            ->assertJsonPath('data.most_watched_movies', [])
            ->assertJsonPath('data.highest_rated_movies', [])
            ->assertJsonPath('data.popular_genres', [])
            ->assertJsonPath('data.recent_activity', [])
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.password');
    }

    public function test_dashboard_uses_stored_data_for_kpis_and_rankings(): void
    {
        $admin = $this->admin();
        $first = $this->movie('First', [['tmdb_id' => 1, 'name' => 'Action']]);
        $second = $this->movie('Second', [['tmdb_id' => 2, 'name' => 'Drama']]);
        $inactive = $this->movie('Inactive', [['tmdb_id' => 1, 'name' => 'Action']], ['deactivated_at' => now()]);
        $one = User::factory()->create();
        $two = User::factory()->create();

        $this->movieStatus($one, $first, now()->subMinute(), true);
        $this->movieStatus($two, $first, now()->subMinutes(2));
        $this->movieStatus($one, $second, null, true);
        $this->movieStatus($two, $inactive, now()->subMinutes(3));
        $this->review($one, $first, 5);
        $this->review($two, $first, 3);
        $this->review($one, $second, 5);
        $this->review($two, $second, 1, Review::STATUS_HIDDEN);

        $this->actingAs($admin, 'web')->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.kpis', [
                'total_users' => 3,
                'total_reviews' => 4,
                'visible_reviews' => 3,
                'total_movies' => 3,
                'total_watched_entries' => 3,
                'total_watchlist_entries' => 2,
            ])
            ->assertJsonPath('data.most_watched_movies.0', [
                'movie_id' => (string) $first->id,
                'title' => 'First',
                'poster_url' => null,
                'watch_count' => 2,
            ])
            ->assertJsonPath('data.most_watched_movies.1.movie_id', (string) $inactive->id)
            ->assertJsonPath('data.highest_rated_movies.0', [
                'movie_id' => (string) $second->id,
                'title' => 'Second',
                'average_rating' => 5,
                'review_count' => 1,
            ])
            ->assertJsonPath('data.highest_rated_movies.1.average_rating', 4)
            ->assertJsonPath('data.highest_rated_movies.1.review_count', 2)
            ->assertJsonPath('data.popular_genres.0', [
                'genre_id' => 1,
                'genre_name' => 'Action',
                'engagement_count' => 3,
            ]);
    }

    public function test_rankings_deduplicate_users_exclude_watchlist_only_and_break_ties_deterministically(): void
    {
        $admin = $this->admin();
        $first = $this->movie('First');
        $second = $this->movie('Second');
        $watcher = User::factory()->create();
        $another = User::factory()->create();

        $this->movieStatus($watcher, $first, now());
        $this->movieStatus($watcher, $second, now());
        $this->movieStatus($another, $second, null, true);
        $this->review($watcher, $first, 4);
        $this->review($another, $second, 4);

        $this->actingAs($admin, 'web')->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.most_watched_movies.0.movie_id', (string) min($first->id, $second->id))
            ->assertJsonPath('data.most_watched_movies.0.watch_count', 1)
            ->assertJsonPath('data.most_watched_movies.1.watch_count', 1)
            ->assertJsonPath('data.highest_rated_movies.0.movie_id', (string) min($first->id, $second->id))
            ->assertJsonPath('data.highest_rated_movies.0.average_rating', 4)
            ->assertJsonPath('data.highest_rated_movies.0.review_count', 1);
    }

    public function test_genres_use_unique_user_movie_engagements_and_multiple_movie_genres(): void
    {
        $admin = $this->admin();
        $movie = $this->movie('Genre movie', [
            ['tmdb_id' => 28, 'name' => 'Action'],
            ['tmdb_id' => 12, 'name' => 'Adventure'],
            ['tmdb_id' => 28, 'name' => 'Action'],
            ['name' => 'Indie'],
            ['name' => '   '],
        ]);
        $user = User::factory()->create();
        $this->movieStatus($user, $movie, now());
        $this->review($user, $movie, 5);

        $this->actingAs($admin, 'web')->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.popular_genres', [
                ['genre_id' => 28, 'genre_name' => 'Action', 'engagement_count' => 1],
                ['genre_id' => 12, 'genre_name' => 'Adventure', 'engagement_count' => 1],
                ['genre_id' => null, 'genre_name' => 'Indie', 'engagement_count' => 1],
            ]);
    }

    public function test_recent_activity_is_newest_first_bounded_and_read_only(): void
    {
        $admin = $this->admin();
        $movie = $this->movie('Activity movie');
        $user = User::factory()->create(['name' => 'Activity user']);
        $base = Carbon::parse('2026-01-01 10:00:00');
        $review = $this->review($user, $movie, 4, Review::STATUS_VISIBLE, $base);
        $status = $this->movieStatus($user, $movie, $base->copy()->addMinute());

        for ($index = 0; $index < 19; $index++) {
            $this->review(User::factory()->create(), $this->movie("Movie {$index}"), 3, Review::STATUS_VISIBLE, $base->copy()->addMinutes($index + 2));
        }

        $before = [
            'reviews' => Review::count(),
            'statuses' => UserMovieStatus::count(),
            'users' => User::count(),
        ];
        $response = $this->actingAs($admin, 'web')->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(20, 'data.recent_activity')
            ->assertJsonPath('data.recent_activity.0.event_type', 'review_created')
            ->assertJsonMissingPath('data.recent_activity.0.email')
            ->assertJsonMissingPath('data.recent_activity.0.review_text');

        $activity = $response->json('data.recent_activity');
        $this->assertSame('movie_watched', collect($activity)->firstWhere('movie_id', (string) $movie->id)['event_type']);
        $this->assertSame($before['reviews'], Review::count());
        $this->assertSame($before['statuses'], UserMovieStatus::count());
        $this->assertSame($before['users'], User::count());
        $this->assertNotNull($review->id);
        $this->assertNotNull($status->id);
    }

    public function test_rankings_are_limited_to_ten_results(): void
    {
        $admin = $this->admin();
        for ($index = 0; $index < 11; $index++) {
            $movie = $this->movie("Movie {$index}", [['tmdb_id' => $index + 1, 'name' => "Genre {$index}"]]);
            $user = User::factory()->create();
            $this->movieStatus($user, $movie, now()->subMinutes($index));
            $this->review($user, $movie, 5);
        }

        $this->actingAs($admin, 'web')->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(10, 'data.most_watched_movies')
            ->assertJsonCount(10, 'data.highest_rated_movies')
            ->assertJsonCount(10, 'data.popular_genres');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function movie(string $title, array $genres = [], array $attributes = []): Movie
    {
        return Movie::factory()->create(['title' => $title, 'genres' => $genres, ...$attributes]);
    }

    private function movieStatus(User $user, Movie $movie, ?Carbon $watchedAt, bool $watchlist = false): UserMovieStatus
    {
        return UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => $watchlist,
            'watched_at' => $watchedAt,
        ]);
    }

    private function review(User $user, Movie $movie, int $rating, string $status = Review::STATUS_VISIBLE, ?Carbon $createdAt = null): Review
    {
        $review = $user->reviews()->make(['rating' => $rating]);
        $review->movie()->associate($movie);
        $review->forceFill(['moderation_status' => $status])->save();

        if ($createdAt !== null) {
            $review->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
        }

        return $review;
    }
}
