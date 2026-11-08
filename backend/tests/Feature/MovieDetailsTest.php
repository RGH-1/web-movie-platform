<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MovieDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/movies/';
    private const TMDB_URL = 'https://api.themoviedb.org/3/movie/*';
    private const TMDB_SEARCH_URL = 'https://api.themoviedb.org/3/search/movie*';

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

    private function fakeTmdb(
        array $payload,
        int $status = 200,
        array $headers = []
    ): void {
        Http::fake([
            self::TMDB_URL => Http::response($payload, $status, $headers),
        ]);
    }

    public function test_guest_cannot_view_movie_details(): void
    {
        Http::fake();

        $this->getJson(self::URL . '597')
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_incomplete_profile_cannot_view_movie_details(): void
    {
        Http::fake();

        $user = User::factory()->create(['name' => ' ']);

        $this->actingAs($user, 'web')
            ->getJson(self::URL . '597')
            ->assertForbidden()
            ->assertJsonPath('code', 'PROFILE_INCOMPLETE');

        Http::assertNothingSent();
    }

    public function test_successful_movie_details_returns_expected_json(): void
    {
        $this->fakeTmdb([
            'id' => 597,
            'title' => 'Titanic',
            'overview' => 'A romantic drama.',
            'release_date' => '1997-12-19',
            'runtime' => 194,
            'genres' => [
                ['id' => 18, 'name' => 'Drama'],
                ['id' => 10749, 'name' => 'Romance'],
            ],
            'poster_path' => '/titanic.jpg',
            'backdrop_path' => '/titanic-backdrop.jpg',
            'vote_average' => 7.9,
            'vote_count' => 100,
            'credits' => [
                'cast' => [
                    [
                        'id' => 100,
                        'name' => 'Test Actor',
                        'character' => 'Test Character',
                        'order' => 0,
                        'profile_path' => '/actor.jpg',
                    ],
                ],
                'crew' => [
                    [
                        'id' => 200,
                        'name' => 'Test Director',
                        'job' => 'Director',
                    ],
                ],
            ],
            'videos' => [
                'results' => [
                    [
                        'site' => 'YouTube',
                        'type' => 'Trailer',
                        'key' => 'abcdefghijk',
                        'official' => true,
                    ],
                ],
            ],
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '597')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'tmdb_id',
                    'title',
                    'overview',
                    'release_date',
                    'runtime_minutes',
                    'genres',
                    'poster_url',
                    'backdrop_url',
                    'vote_average',
                    'cast',
                    'director',
                    'trailer',
                ],
            ])
            ->assertJsonPath('data.tmdb_id', 597)
            ->assertJsonPath('data.title', 'Titanic')
            ->assertJsonPath('data.runtime_minutes', 194)
            ->assertJsonPath('data.genres.0.name', 'Drama')
            ->assertJsonPath(
                'data.poster_url',
                'https://image.tmdb.org/t/p/w342/titanic.jpg'
            )
            ->assertJsonPath(
                'data.backdrop_url',
                'https://image.tmdb.org/t/p/w780/titanic-backdrop.jpg'
            )
            ->assertJsonPath('data.vote_average', 7.9)
            ->assertJsonPath('data.cast.0.name', 'Test Actor')
            ->assertJsonPath('data.director', 'Test Director')
            ->assertJsonPath('data.trailer.key', 'abcdefghijk');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/movie/597')
                && $request['language'] === 'en-US'
                && $request['append_to_response'] === 'credits,videos';
        });

        Http::assertSentCount(1);
    }

    public function test_missing_optional_movie_fields_return_safe_defaults(): void
    {
        $this->fakeTmdb([
            'id' => 597,
            'title' => 'Titanic',
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '597')
            ->assertOk()
            ->assertJsonPath('data.overview', null)
            ->assertJsonPath('data.release_date', null)
            ->assertJsonPath('data.runtime_minutes', null)
            ->assertJsonPath('data.genres', [])
            ->assertJsonPath('data.poster_url', null)
            ->assertJsonPath('data.backdrop_url', null)
            ->assertJsonPath('data.vote_average', null)
            ->assertJsonPath('data.cast', [])
            ->assertJsonPath('data.director', null)
            ->assertJsonPath('data.trailer', null);
    }

    public function test_nonexistent_movie_returns_404(): void
    {
        $this->fakeTmdb([
            'status_message' => 'The resource you requested could not be found.',
        ], 404);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '999999')
            ->assertNotFound()
            ->assertJsonPath('code', 'MOVIE_NOT_FOUND');

        Http::assertSentCount(1);
    }

    public function test_invalid_movie_identifiers_return_422(): void
    {
        Http::fake();

        $this->actingAs($this->createCompleteUser(), 'web');

        foreach (['abc', '0', '-1', '1.5', '2147483648'] as $id) {
            $this->getJson(self::URL . $id)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'INVALID_MOVIE_ID');
        }

        Http::assertNothingSent();
    }

    public function test_tmdb_server_failure_returns_controlled_error(): void
    {
        $this->fakeTmdb([
            'status_message' => 'Sensitive upstream error.',
        ], 500);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '597')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_DETAILS_UNAVAILABLE')
            ->assertDontSee('Sensitive upstream error');

        Http::assertSentCount(1);
    }

    public function test_tmdb_rate_limit_returns_503(): void
    {
        $this->fakeTmdb([], 429, ['Retry-After' => '60']);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '597')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MOVIE_DETAILS_RATE_LIMITED')
            ->assertHeader('Retry-After', '60');

        Http::assertSentCount(1);
    }

    public function test_malformed_tmdb_response_returns_502(): void
    {
        $this->fakeTmdb([
            'id' => 597,
            'title' => '',
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '597')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_DETAILS_UNAVAILABLE');

        Http::assertSentCount(1);
    }

    public function test_tmdb_connection_failure_returns_502(): void
    {
        Http::fake([
            self::TMDB_URL => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '597')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_DETAILS_UNAVAILABLE')
            ->assertDontSee('Connection timed out');
    }

    public function test_search_route_does_not_conflict_with_details_route(): void
    {
        Http::fake([
            self::TMDB_SEARCH_URL => Http::response([
                'page' => 1,
                'total_pages' => 0,
                'total_results' => 0,
                'results' => [],
            ], 200),
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson('/api/v1/movies/search?query=Titanic')
            ->assertOk()
            ->assertJsonPath('data', []);

        Http::assertSentCount(1);
    }
}
