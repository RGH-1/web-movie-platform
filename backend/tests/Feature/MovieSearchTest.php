<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MovieSearchTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/movies/search';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::preventStrayRequests();
    }

    private function createCompleteUser(): User
    {
        return User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    public function test_guest_cannot_search_movies(): void
    {
        Http::fake();

        $this->getJson(self::URL . '?query=Titanic')
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_incomplete_profile_cannot_search_movies(): void
    {
        Http::fake();

        $user = User::factory()->create(['name' => ' ']);

        $this->actingAs($user, 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertForbidden()
            ->assertJsonPath('code', 'PROFILE_INCOMPLETE');

        Http::assertNothingSent();
    }

    public function test_missing_query_returns_validation_error(): void
    {
        Http::fake();

        $user = $this->createCompleteUser();

        $this->actingAs($user, 'web')
            ->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');

        Http::assertNothingSent();
    }

    public function test_invalid_page_returns_validation_error(): void
    {
        Http::fake();

        $user = $this->createCompleteUser();

        $this->actingAs($user, 'web')
            ->getJson(self::URL . '?query=Titanic&page=501')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        Http::assertNothingSent();
    }

    public function test_successful_movie_search_returns_expected_json(): void
    {
        Http::fake([
            'https://api.themoviedb.org/3/search/movie*' => Http::response([
                'page' => 1,
                'total_pages' => 1,
                'total_results' => 1,
                'results' => [
                    [
                        'id' => 597,
                        'title' => 'Titanic',
                        'overview' => 'A romantic drama.',
                        'release_date' => '1997-12-19',
                        'poster_path' => '/titanic.jpg',
                        'vote_average' => 7.9,
                        'vote_count' => 100,
                    ],
                ],
            ], 200),
        ]);

        $user = $this->createCompleteUser();

        $this->actingAs($user, 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertOk()
            ->assertJsonPath('data.0.tmdb_id', 597)
            ->assertJsonPath('data.0.title', 'Titanic')
            ->assertJsonPath('data.0.overview', 'A romantic drama.')
            ->assertJsonPath('data.0.release_date', '1997-12-19')
            ->assertJsonPath(
                'data.0.poster_url',
                'https://image.tmdb.org/t/p/w342/titanic.jpg'
            )
            ->assertJsonPath('data.0.vote_average', 7.9)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.total_pages', 1)
            ->assertJsonPath('meta.total_results', 1);

        Http::assertSentCount(1);
    }

    public function test_tmdb_rate_limit_returns_503(): void
    {
        Http::fake([
            'https://api.themoviedb.org/3/search/movie*' =>
                Http::response([], 429, ['Retry-After' => '60']),
        ]);

        $user = $this->createCompleteUser();

        $this->actingAs($user, 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MOVIE_SEARCH_RATE_LIMITED')
            ->assertHeader('Retry-After', '60');

        Http::assertSentCount(1);
    }

    public function test_search_with_no_results_returns_empty_array(): void
    {
        Http::fake([
            'https://api.themoviedb.org/3/search/movie*' => Http::response([
                'page' => 1,
                'total_pages' => 0,
                'total_results' => 0,
                'results' => [],
            ], 200),
        ]);

        $user = $this->createCompleteUser();

        $this->actingAs($user, 'web')
            ->getJson(self::URL . '?query=NonexistentMovie123')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.total_pages', 0)
            ->assertJsonPath('meta.total_results', 0);

        Http::assertSentCount(1);
    }

    public function test_tmdb_server_error_returns_502(): void
    {
        Http::fake([
            'https://api.themoviedb.org/3/search/movie*' =>
                Http::response([], 500),
        ]);

        $user = $this->createCompleteUser();

        $this->actingAs($user, 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_SEARCH_UNAVAILABLE');

        Http::assertSentCount(1);
    }

    public function test_tmdb_connection_timeout_returns_502(): void
    {
        Http::fake([
            'https://api.themoviedb.org/3/search/movie*' =>
                function () {
                    throw new ConnectionException('Connection timed out');
                },
        ]);

        $user = $this->createCompleteUser();

        $this->actingAs($user, 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_SEARCH_UNAVAILABLE')
            ->assertDontSee('Connection timed out');

        // No response is recorded when a connection exception is thrown.
        Http::assertNothingSent();
    }
}
