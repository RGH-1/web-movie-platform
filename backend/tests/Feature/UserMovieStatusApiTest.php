<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Models\UserMovieStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserMovieStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private function statusUrl(Movie $movie): string
    {
        return "/api/v1/me/movies/{$movie->id}/status";
    }

    private function createStatus(
        User $user,
        Movie $movie,
        bool $inWatchlist = false,
        mixed $watchedAt = null
    ): UserMovieStatus {
        return UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => $inWatchlist,
            'watched_at' => $watchedAt,
        ]);
    }

    public function test_guests_cannot_access_movie_status_endpoints(): void
    {
        $movie = Movie::factory()->create();

        $this->getJson('/api/v1/me/movies?filter=watchlist')
            ->assertUnauthorized();

        $this->getJson($this->statusUrl($movie))
            ->assertUnauthorized();

        $this->patchJson($this->statusUrl($movie), [
            'in_watchlist' => true,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('user_movie_statuses', 0);
    }

    public function test_incomplete_profiles_cannot_access_movie_status_endpoints(): void
    {
        $user = User::factory()->create(['name' => ' ']);
        $movie = Movie::factory()->create();

        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/me/movies?filter=watchlist')
            ->assertForbidden()
            ->assertJsonPath('code', 'PROFILE_INCOMPLETE');

        $this->getJson($this->statusUrl($movie))
            ->assertForbidden();

        $this->patchJson($this->statusUrl($movie), [
            'in_watchlist' => true,
        ])->assertForbidden();
    }

    public function test_watchlist_returns_only_current_users_movies_with_metadata(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $watchlistedMovie = Movie::factory()->create([
            'title' => 'Interstellar',
            'poster_url' => 'https://example.com/interstellar.jpg',
        ]);

        $watchedOnlyMovie = Movie::factory()->create();
        $otherUsersMovie = Movie::factory()->create();

        $this->createStatus($user, $watchlistedMovie, true);
        $this->createStatus($user, $watchedOnlyMovie, false, now());
        $this->createStatus($otherUser, $otherUsersMovie, true);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/me/movies?filter=watchlist')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.filter', 'watchlist')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.movie_id', (string) $watchlistedMovie->id)
            ->assertJsonPath('data.0.in_watchlist', true)
            ->assertJsonPath('data.0.is_watched', false)
            ->assertJsonPath('data.0.movie.title', 'Interstellar')
            ->assertJsonPath(
                'data.0.movie.poster_url',
                'https://example.com/interstellar.jpg'
            )
            ->assertJsonStructure([
                'data' => [[
                    'movie_id',
                    'in_watchlist',
                    'is_watched',
                    'watched_at',
                    'movie' => [
                        'id',
                        'tmdb_id',
                        'source',
                        'title',
                        'overview',
                        'poster_url',
                        'backdrop_url',
                        'release_date',
                        'vote_average',
                        'genres',
                    ],
                ]],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
                'links' => [
                    'next',
                    'previous',
                ],
            ]);
    }

    public function test_watched_history_returns_only_watched_movies_in_date_order(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $olderMovie = Movie::factory()->create();
        $newerMovie = Movie::factory()->create();
        $watchlistOnlyMovie = Movie::factory()->create();
        $otherUsersMovie = Movie::factory()->create();

        $this->createStatus(
            $user,
            $olderMovie,
            false,
            now()->subDays(3)
        );

        $this->createStatus(
            $user,
            $newerMovie,
            true,
            now()->subDay()
        );

        $this->createStatus($user, $watchlistOnlyMovie, true);

        $this->createStatus(
            $otherUser,
            $otherUsersMovie,
            false,
            now()
        );

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/me/movies?filter=watched')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.filter', 'watched')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.movie_id', (string) $newerMovie->id)
            ->assertJsonPath('data.1.movie_id', (string) $olderMovie->id)
            ->assertJsonPath('data.0.is_watched', true)
            ->assertJsonPath('data.1.is_watched', true);
    }

    public function test_watchlist_supports_pagination(): void
    {
        $user = User::factory()->create();

        $movies = Movie::factory()->count(5)->create();

        foreach ($movies as $movie) {
            $this->createStatus($user, $movie, true);
        }

        $this->actingAs($user, 'web');

        $this->getJson(
            '/api/v1/me/movies?filter=watchlist&page=1&per_page=2'
        )
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5);

        $this->getJson(
            '/api/v1/me/movies?filter=watchlist&page=3&per_page=2'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_show_returns_default_status_when_no_record_exists(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson($this->statusUrl($movie))
            ->assertOk()
            ->assertJsonPath('data.movie_id', (string) $movie->id)
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.is_watched', false)
            ->assertJsonPath('data.watched_at', null);

        $this->assertDatabaseCount('user_movie_statuses', 0);
    }

    public function test_show_returns_only_the_authenticated_users_status(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->createStatus($user, $movie, false, now());
        $this->createStatus($otherUser, $movie, true);

        $this->actingAs($user, 'web')
            ->getJson($this->statusUrl($movie))
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.is_watched', true);

        $this->assertDatabaseCount('user_movie_statuses', 2);
    }

    public function test_patch_adds_and_removes_movie_from_watchlist(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($user, 'web');

        $this->patchJson($this->statusUrl($movie), [
            'in_watchlist' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', true)
            ->assertJsonPath('data.is_watched', false);

        $this->assertDatabaseHas('user_movie_statuses', [
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
            'watched_at' => null,
        ]);

        $this->patchJson($this->statusUrl($movie), [
            'in_watchlist' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.is_watched', false);

        $this->assertDatabaseCount('user_movie_statuses', 0);
    }

    public function test_patch_updates_watched_status_independently(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->createStatus($user, $movie, true);

        $this->actingAs($user, 'web');

        $this->patchJson($this->statusUrl($movie), [
            'is_watched' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', true)
            ->assertJsonPath('data.is_watched', true);

        $status = UserMovieStatus::firstOrFail();

        $this->assertNotNull($status->watched_at);
        $this->assertTrue($status->in_watchlist);

        $this->patchJson($this->statusUrl($movie), [
            'in_watchlist' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.is_watched', true);

        $this->assertDatabaseCount('user_movie_statuses', 1);

        $this->patchJson($this->statusUrl($movie), [
            'is_watched' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.is_watched', false)
            ->assertJsonPath('data.watched_at', null);

        $this->assertDatabaseCount('user_movie_statuses', 0);
    }

    public function test_repeated_updates_do_not_duplicate_records_or_reset_watched_time(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($user, 'web');

        $firstResponse = $this->patchJson(
            $this->statusUrl($movie),
            ['is_watched' => true]
        );

        $firstResponse->assertOk();

        $originalWatchedAt = $firstResponse->json('data.watched_at');

        $this->assertNotNull($originalWatchedAt);

        $this->travel(2)->hours();

        $this->patchJson($this->statusUrl($movie), [
            'is_watched' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.watched_at', $originalWatchedAt);

        $this->patchJson($this->statusUrl($movie), [
            'in_watchlist' => true,
        ])->assertOk();

        $this->patchJson($this->statusUrl($movie), [
            'in_watchlist' => true,
        ])->assertOk();

        $this->assertDatabaseCount('user_movie_statuses', 1);
    }

    public function test_patch_cannot_modify_another_users_status(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->createStatus($otherUser, $movie, false, now());

        $this->actingAs($user, 'web')
            ->patchJson($this->statusUrl($movie), [
                'in_watchlist' => true,
                'user_id' => $otherUser->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', true)
            ->assertJsonPath('data.is_watched', false);

        $this->assertDatabaseHas('user_movie_statuses', [
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
            'watched_at' => null,
        ]);

        $this->assertDatabaseHas('user_movie_statuses', [
            'user_id' => $otherUser->id,
            'movie_id' => $movie->id,
            'in_watchlist' => false,
        ]);

        $this->assertDatabaseCount('user_movie_statuses', 2);
    }

    public function test_invalid_movie_ids_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/me/movies/abc/status')
            ->assertUnprocessable();

        $this->getJson('/api/v1/me/movies/0/status')
            ->assertUnprocessable();

        $this->getJson('/api/v1/me/movies/99999999/status')
            ->assertNotFound();

        $this->patchJson('/api/v1/me/movies/abc/status', [
            'in_watchlist' => true,
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/me/movies/99999999/status', [
            'in_watchlist' => true,
        ])->assertNotFound();

        $this->assertDatabaseCount('user_movie_statuses', 0);
    }

    public function test_invalid_status_fields_are_rejected(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($user, 'web');

        $invalidBodies = [
            [],
            ['in_watchlist' => 'invalid'],
            ['is_watched' => 'invalid'],
            ['in_watchlist' => null],
            ['is_watched' => null],
            ['in_watchlist' => 5],
            ['user_id' => 999],
        ];

        foreach ($invalidBodies as $body) {
            $this->patchJson($this->statusUrl($movie), $body)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('user_movie_statuses', 0);
    }

    public function test_invalid_collection_filters_and_pagination_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $invalidQueries = [
            '/api/v1/me/movies',
            '/api/v1/me/movies?filter=invalid',
            '/api/v1/me/movies?filter=watchlist&page=0',
            '/api/v1/me/movies?filter=watched&per_page=0',
            '/api/v1/me/movies?filter=watchlist&per_page=51',
        ];

        foreach ($invalidQueries as $url) {
            $this->getJson($url)->assertUnprocessable();
        }
    }
}
