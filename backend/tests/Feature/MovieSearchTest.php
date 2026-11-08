<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MovieSearchTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/movies/search';
    private const TMDB_URL = 'https://api.themoviedb.org/3/search/movie*';

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

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');

        Http::assertNothingSent();
    }

    public function test_invalid_page_returns_validation_error(): void
    {
        Http::fake();

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Titanic&page=501')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        Http::assertNothingSent();
    }

    public function test_blank_query_is_rejected_without_contacting_tmdb(): void
    {
        Http::fake();

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=%20%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');

        Http::assertNothingSent();
    }

    public function test_array_query_is_rejected_without_contacting_tmdb(): void
    {
        Http::fake();

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query[]=Titanic')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');

        Http::assertNothingSent();
    }

    public function test_overlong_query_is_rejected_without_contacting_tmdb(): void
    {
        Http::fake();

        $query = str_repeat('a', 201);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=' . $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');

        Http::assertNothingSent();
    }

    public function test_invalid_page_values_are_rejected_without_contacting_tmdb(): void
    {
        Http::fake();

        $this->actingAs($this->createCompleteUser(), 'web');

        foreach (['0', '1.5', '501', 'abc', ''] as $page) {
            $this->getJson(self::URL . '?query=Titanic&page=' . $page)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('page');
        }

        Http::assertNothingSent();
    }

    public function test_successful_movie_search_returns_expected_json(): void
    {
        $this->fakeTmdb([
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
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'tmdb_id',
                        'title',
                        'overview',
                        'release_date',
                        'poster_url',
                        'vote_average',
                    ],
                ],
                'meta' => [
                    'page',
                    'total_pages',
                    'total_results',
                ],
            ])
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

    public function test_search_trims_query_and_forwards_expected_parameters(): void
    {
        $this->fakeTmdb([
            'page' => 1,
            'total_pages' => 0,
            'total_results' => 0,
            'results' => [],
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=%20Titanic%20')
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            dump('TMDB request URL:', $request->url());
            dump('TMDB request parameters:', $request->data());

            return true;
        });

        Http::assertSentCount(1);
    }

    public function test_second_page_returns_correct_pagination(): void
    {
        $this->fakeTmdb([
            'page' => 2,
            'total_pages' => 3,
            'total_results' => 45,
            'results' => [
                [
                    'id' => 123,
                    'title' => 'Example Movie',
                    'overview' => 'Example overview',
                    'release_date' => '2020-01-01',
                    'poster_path' => null,
                    'vote_average' => 6.5,
                    'vote_count' => 10,
                ],
            ],
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Example&page=2')
            ->assertOk()
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.total_pages', 3)
            ->assertJsonPath('meta.total_results', 45)
            ->assertJsonPath('data.0.tmdb_id', 123);

        Http::assertSent(fn (Request $request): bool =>
            (int) $request['page'] === 2
        );

        Http::assertSentCount(1);
    }

    public function test_search_with_no_results_returns_empty_array(): void
    {
        $this->fakeTmdb([
            'page' => 1,
            'total_pages' => 0,
            'total_results' => 0,
            'results' => [],
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=NonexistentMovie123')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.total_pages', 0)
            ->assertJsonPath('meta.total_results', 0);

        Http::assertSentCount(1);
    }

    public function test_page_beyond_results_returns_empty_data_with_existing_totals(): void
    {
        $this->fakeTmdb([
            'page' => 5,
            'total_pages' => 2,
            'total_results' => 30,
            'results' => [],
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Titanic&page=5')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.page', 5)
            ->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('meta.total_results', 30);

        Http::assertSentCount(1);
    }

    public function test_total_pages_is_capped_at_500(): void
    {
        $this->fakeTmdb([
            'page' => 1,
            'total_pages' => 800,
            'total_results' => 16000,
            'results' => [],
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Movie')
            ->assertOk()
            ->assertJsonPath('meta.total_pages', 500)
            ->assertJsonPath('meta.total_results', 16000);
    }

    public function test_missing_optional_movie_fields_return_null(): void
    {
        $this->fakeTmdb([
            'page' => 1,
            'total_pages' => 1,
            'total_results' => 1,
            'results' => [
                [
                    'id' => 999,
                    'title' => 'Unknown Movie',
                ],
            ],
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Unknown')
            ->assertOk()
            ->assertJsonPath('data.0.tmdb_id', 999)
            ->assertJsonPath('data.0.overview', null)
            ->assertJsonPath('data.0.release_date', null)
            ->assertJsonPath('data.0.poster_url', null)
            ->assertJsonPath('data.0.vote_average', null);
    }

    public function test_movie_without_votes_returns_null_rating(): void
    {
        $this->fakeTmdb([
            'page' => 1,
            'total_pages' => 1,
            'total_results' => 1,
            'results' => [
                [
                    'id' => 777,
                    'title' => 'Unrated Movie',
                    'vote_average' => 0,
                    'vote_count' => 0,
                ],
            ],
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Unrated')
            ->assertOk()
            ->assertJsonPath('data.0.vote_average', null);
    }

    public function test_tmdb_rate_limit_returns_503(): void
    {
        $this->fakeTmdb([], 429, ['Retry-After' => '60']);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MOVIE_SEARCH_RATE_LIMITED')
            ->assertHeader('Retry-After', '60');

        Http::assertSentCount(1);
    }

    public function test_rate_limit_without_valid_retry_after_defaults_to_30(): void
    {
        $this->fakeTmdb([], 429, ['Retry-After' => 'invalid']);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MOVIE_SEARCH_RATE_LIMITED')
            ->assertHeader('Retry-After', '30');

        Http::assertSentCount(1);
    }

    public function test_tmdb_server_error_returns_502(): void
    {
        $this->fakeTmdb([], 500);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_SEARCH_UNAVAILABLE');

        Http::assertSentCount(1);
    }

    public function test_malformed_tmdb_response_returns_502(): void
    {
        $this->fakeTmdb([
            'page' => 1,
            'results' => 'invalid',
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_SEARCH_UNAVAILABLE');

        Http::assertSentCount(1);
    }

    public function test_tmdb_connection_timeout_returns_502(): void
    {
        Http::fake([
            self::TMDB_URL => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $this->actingAs($this->createCompleteUser(), 'web')
            ->getJson(self::URL . '?query=Titanic')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_SEARCH_UNAVAILABLE')
            ->assertDontSee('Connection timed out');

        Http::assertNothingSent();
    }
}
