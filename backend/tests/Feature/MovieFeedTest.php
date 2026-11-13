<?php

namespace Tests\Feature;

use App\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MovieFeedTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/movies/';
    private const TMDB = 'https://api.themoviedb.org/3';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tmdb.base_url' => self::TMDB,
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::preventStrayRequests();
    }

    #[DataProvider('feeds')]
    public function test_guests_can_fetch_normalized_movie_cards(
        string $category,
        string $query,
        int $page,
    ): void {
        $this->fakeFeed($category, [
            'results' => [
                [
                    'id' => 597,
                    'title' => 'Titanic',
                    'overview' => 'A voyage across the Atlantic.',
                    'release_date' => '1997-12-19',
                    'poster_path' => '/example.jpg',
                    'vote_average' => 7.9,
                    'vote_count' => 200,
                ],
                [
                    'id' => 598,
                    'title' => 'Another Film',
                    'overview' => '',
                    'release_date' => '2026-02-30',
                    'vote_average' => 0,
                    'vote_count' => 0,
                ],
            ],
            'total_pages' => 600,
            'total_results' => 12000,
        ]);

        $this->getJson(self::API . $category . $query)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'tmdb_id' => 597,
                        'local_movie_id' => null,
                        'source' => 'tmdb',
                        'title' => 'Titanic',
                        'overview' => 'A voyage across the Atlantic.',
                        'release_date' => '1997-12-19',
                        'poster_url' => 'https://image.tmdb.org/t/p/w342/example.jpg',
                        'vote_average' => 7.9,
                    ],
                    [
                        'tmdb_id' => 598,
                        'local_movie_id' => null,
                        'source' => 'tmdb',
                        'title' => 'Another Film',
                        'overview' => null,
                        'release_date' => null,
                        'poster_url' => null,
                        'vote_average' => null,
                    ],
                ],
                'meta' => [
                    'page' => $page,
                    'total_pages' => 500,
                    'total_results' => 12000,
                ],
            ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($category, $page): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $params);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === "/3/movie/{$category}"
                && ($params['language'] ?? null) === 'en-US'
                && ($params['page'] ?? null) === (string) $page
                && $request->hasHeader('Authorization', 'Bearer fake-test-token');
        });

        $this->assertDatabaseCount('movies', 0);
    }

    public static function feeds(): array
    {
        return [
            'popular default page' => ['popular', '', 1],
            'upcoming second page' => ['upcoming', '?page=2', 2],
            'upper page limit' => ['popular', '?page=500', 500],
        ];
    }

    public function test_cards_use_active_tmdb_mappings_even_when_ids_collide(): void
    {
        $local = Movie::factory()->create();
        $active = Movie::factory()->tmdb((int) $local->id)->create([
            'title' => 'Stored title',
        ]);
        $inactive = Movie::factory()->tmdb((int) $active->id)->create([
            'deactivated_at' => now(),
        ]);

        $this->fakeFeed('popular', [
            'results' => [
                ['id' => (int) $local->id, 'title' => 'Live TMDB title'],
                ['id' => (int) $active->id, 'title' => 'Inactive mapping'],
                ['id' => (int) $inactive->id, 'title' => 'Unmapped provider movie'],
            ],
            'total_pages' => 1,
            'total_results' => 3,
        ]);

        $this->getJson(self::API . 'popular')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.tmdb_id', (int) $local->id)
            ->assertJsonPath('data.0.local_movie_id', $active->id)
            ->assertJsonPath('data.0.title', 'Live TMDB title')
            ->assertJsonPath('data.1.local_movie_id', null)
            ->assertJsonPath('data.2.local_movie_id', null);

        $this->assertDatabaseCount('movies', 3);
        $this->assertSame('Stored title', $active->refresh()->title);
    }

    public function test_invalid_pages_are_rejected_before_contacting_tmdb(): void
    {
        Http::fake();

        foreach (['popular', 'upcoming'] as $category) {
            foreach (['page=0', 'page=501', 'page=1.5', 'page=abc', 'page=', 'page[]=1'] as $query) {
                $this->getJson(self::API . $category . '?' . $query)
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('page');
            }
        }

        Http::assertNothingSent();
    }

    public function test_empty_page_preserves_provider_totals(): void
    {
        $this->fakeFeed('upcoming', [
            'results' => [],
            'total_pages' => 2,
            'total_results' => 25,
        ]);

        $this->getJson(self::API . 'upcoming?page=3')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'meta' => ['page' => 3, 'total_pages' => 2, 'total_results' => 25],
            ]);
    }

    public function test_rate_limits_return_a_retry_delay(): void
    {
        Http::fake([
            self::TMDB . '/movie/popular*' => Http::response([], 429, ['Retry-After' => '60']),
            self::TMDB . '/movie/upcoming*' => Http::response([], 429),
        ]);

        $this->getJson(self::API . 'popular')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('code', 'MOVIE_FEED_RATE_LIMITED');

        $this->getJson(self::API . 'upcoming')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('code', 'MOVIE_FEED_RATE_LIMITED');
    }

    #[DataProvider('badProviderResponses')]
    public function test_unusable_provider_responses_return_502(array $body, int $status): void
    {
        $this->fakeFeed('popular', $body, $status);

        $this->getJson(self::API . 'popular')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_FEED_UNAVAILABLE')
            ->assertDontSee('Internal provider response');
    }

    public static function badProviderResponses(): array
    {
        return [
            'upstream error' => [['message' => 'Internal provider response'], 500],
            'missing results' => [['total_pages' => 1, 'total_results' => 1], 200],
            'invalid card' => [[
                'results' => [['id' => 0, 'title' => 'Invalid']],
                'total_pages' => 1,
                'total_results' => 1,
            ], 200],
        ];
    }

    public function test_connection_failure_returns_502(): void
    {
        Http::fake([
            self::TMDB . '/movie/upcoming*' => Http::failedConnection('Provider connection lost'),
        ]);

        $this->getJson(self::API . 'upcoming')
            ->assertStatus(502)
            ->assertJsonPath('code', 'MOVIE_FEED_UNAVAILABLE')
            ->assertDontSee('Provider connection lost');
    }

    private function fakeFeed(string $category, array $body, int $status = 200): void
    {
        Http::fake([
            self::TMDB . '/movie/' . $category . '*' => Http::response($body, $status),
        ]);
    }
}