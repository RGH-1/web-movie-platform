<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Models\UserMovieStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserMovieStatusRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function actAsFreshUser(User $user): void
    {
        app('auth')->forgetGuards();

        $this->actingAs($user, 'web');
    }


    private function statusUrl(Movie $movie): string
    {
        return "/api/v1/me/movies/{$movie->id}/status";
    }

    public function test_unwatching_and_rewatching_creates_a_new_watched_timestamp(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($user, 'web');

        $firstResponse = $this->patchJson(
            $this->statusUrl($movie),
            ['is_watched' => true]
        );

        $firstResponse->assertOk()
            ->assertJsonPath('data.is_watched', true)
            ->assertJsonPath('data.in_watchlist', false);

        $firstWatchedAt = $firstResponse->json('data.watched_at');

        $this->assertNotNull($firstWatchedAt);
        $this->assertDatabaseCount('user_movie_statuses', 1);

        $this->travel(3)->hours();

        $this->patchJson($this->statusUrl($movie), [
            'is_watched' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_watched', false)
            ->assertJsonPath('data.watched_at', null);

        $this->assertDatabaseCount('user_movie_statuses', 0);

        $secondResponse = $this->patchJson(
            $this->statusUrl($movie),
            ['is_watched' => true]
        );

        $secondResponse->assertOk()
            ->assertJsonPath('data.is_watched', true);

        $secondWatchedAt = $secondResponse->json('data.watched_at');

        $this->assertNotNull($secondWatchedAt);
        $this->assertNotEquals(
            $firstWatchedAt,
            $secondWatchedAt
        );

        $this->assertDatabaseCount('user_movie_statuses', 1);

        $this->assertDatabaseHas('user_movie_statuses', [
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => false,
        ]);
    }

    public function test_two_users_can_manage_the_same_movie_independently(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actAsFreshUser($userA);

        $this->patchJson($this->statusUrl($movie), [
            'in_watchlist' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', true)
            ->assertJsonPath('data.is_watched', false);

        $this->actAsFreshUser($userB);

        $this->patchJson($this->statusUrl($movie), [
            'is_watched' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.is_watched', true);

        $this->getJson($this->statusUrl($movie))
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.is_watched', true);

        $this->actAsFreshUser($userA);

        $this->getJson($this->statusUrl($movie))
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', true)
            ->assertJsonPath('data.is_watched', false);

        $this->assertDatabaseCount('user_movie_statuses', 2);

        $this->patchJson($this->statusUrl($movie), [
            'in_watchlist' => false,
        ])->assertOk();

        $this->assertDatabaseMissing('user_movie_statuses', [
            'user_id' => $userA->id,
            'movie_id' => $movie->id,
        ]);

        $this->actAsFreshUser($userB);

        $this->getJson($this->statusUrl($movie))
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.is_watched', true);

        $this->assertDatabaseHas('user_movie_statuses', [
            'user_id' => $userB->id,
            'movie_id' => $movie->id,
        ]);

        $this->assertDatabaseCount('user_movie_statuses', 1);
    }

    public function test_watched_history_pagination_excludes_other_users_and_unwatched_movies(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $oldestMovie = Movie::factory()->create();
        $middleMovie = Movie::factory()->create();
        $newestMovie = Movie::factory()->create();

        $watchlistOnlyMovie = Movie::factory()->create();
        $otherUsersMovie = Movie::factory()->create();

        foreach (
            [
                [$oldestMovie, 3],
                [$middleMovie, 2],
                [$newestMovie, 1],
            ] as [$movie, $daysAgo]
        ) {
            UserMovieStatus::create([
                'user_id' => $user->id,
                'movie_id' => $movie->id,
                'in_watchlist' => false,
                'watched_at' => now()->subDays($daysAgo),
            ]);
        }

        UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $watchlistOnlyMovie->id,
            'in_watchlist' => true,
        ]);

        UserMovieStatus::create([
            'user_id' => $otherUser->id,
            'movie_id' => $otherUsersMovie->id,
            'watched_at' => now(),
        ]);

        $this->actingAs($user, 'web');

        $this->getJson(
            '/api/v1/me/movies?filter=watched&page=1&per_page=2'
        )
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.filter', 'watched')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath(
                'data.0.movie_id',
                (string) $newestMovie->id
            )
            ->assertJsonPath(
                'data.1.movie_id',
                (string) $middleMovie->id
            );

        $this->getJson(
            '/api/v1/me/movies?filter=watched&page=2&per_page=2'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath(
                'data.0.movie_id',
                (string) $oldestMovie->id
            );
    }
}
