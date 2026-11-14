<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MovieDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3', 'services.tmdb.token' => 'secret-test-token']);
        Http::preventStrayRequests();
    }

    private function fakeMovies(array $results = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/genre/movie/list*' => Http::response(['genres' => [['id' => 28, 'name' => 'Action']]]),
            '*/discover/movie*' => Http::response(['results' => $results, 'total_pages' => 600, 'total_results' => 12000]),
        ]);
    }

    public function test_default_discovery_normalizes_cards_and_attaches_local_ids_without_importing(): void
    {
        $movie = Movie::factory()->tmdb(597)->create();
        $this->fakeMovies([['id' => 597, 'title' => 'Titanic', 'overview' => 'A voyage.', 'poster_path' => '/poster.jpg', 'release_date' => '1997-12-19', 'genre_ids' => [18, 10749], 'vote_average' => 7.9, 'vote_count' => 200]]);
        $this->getJson('/api/v1/movies/discover')->assertOk()->assertExactJson([
            'data' => [['tmdb_id' => 597, 'local_movie_id' => (string) $movie->id, 'source' => 'tmdb', 'title' => 'Titanic', 'overview' => 'A voyage.', 'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg', 'release_date' => '1997-12-19', 'genre_ids' => [18, 10749], 'vote_average' => 7.9]],
            'meta' => ['page' => 1, 'total_pages' => 500, 'total_results' => 12000],
        ]);
        Http::assertSent(fn (Request $r) => $r['sort_by'] === 'popularity.desc' && $r['page'] === 1 && $r['language'] === 'en-US' && $r->hasHeader('Authorization', 'Bearer secret-test-token'));
        $this->assertDatabaseCount('movies', 1);
    }

    public function test_filters_individually_and_combined_and_all_sorts_are_forwarded(): void
    {
        $cases = [
            ['genre_id=28', ['with_genres' => '28']],
            ['year=2024', ['primary_release_year' => '2024']],
            ['min_rating=0', ['vote_average.gte' => '0']],
            ['min_rating=10', ['vote_average.gte' => '10']],
            ['page=500', ['page' => '500']],
            ['genre_id=28&year=2024&min_rating=7&page=2&sort_by=rating_desc&with_genres=99', ['with_genres' => '28', 'primary_release_year' => '2024', 'vote_average.gte' => '7', 'page' => '2', 'sort_by' => 'vote_average.desc']],
        ];
        foreach (TmdbService::DISCOVERY_SORTS as $option => $sort) {
            $cases[] = ['sort_by='.$option, ['sort_by' => $sort]];
        }
        foreach ($cases as [$query, $expected]) {
            $this->fakeMovies();
            $this->getJson('/api/v1/movies/discover?'.$query)->assertOk()->assertJsonPath('data', []);
            Http::assertSent(function (Request $r) use ($expected): bool {
                if (! str_contains($r->url(), '/discover/movie')) {
                    return false;
                }
                foreach ($expected as $key => $value) {
                    if ((string) $r[$key] !== $value) {
                        return false;
                    }
                }

                return true;
            });
        }
    }

    public function test_invalid_filters_return_useful_validation_errors_without_discovery_calls(): void
    {
        foreach ([
            'genre_id' => ['0', '-1', 'abc', '1.5', '', '999', ['28']],
            'year' => ['999', '1800', '9999', '2024.5', '', ['2024']],
            'min_rating' => ['-1', '11', 'abc', '', ['7']],
            'sort_by' => ['vote_average.desc', 'arbitrary', '', ['rating_desc']],
            'page' => ['0', '501', '1.5', 'abc', '', ['1']],
        ] as $field => $values) {
            foreach ($values as $value) {
                $this->fakeMovies();
                $this->getJson('/api/v1/movies/discover?'.http_build_query([$field => $value]))
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
                Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/discover/movie'));
            }
        }
    }

    public function test_official_genres_are_exposed(): void
    {
        $this->fakeMovies();
        $this->getJson('/api/v1/movies/genres')->assertOk()->assertExactJson(['data' => [['id' => 28, 'name' => 'Action']]]);
        Http::assertSentCount(1);
    }

    public function test_empty_results_are_successful(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/discover/movie*' => Http::response(['results' => [], 'total_pages' => 0, 'total_results' => 0])]);
        $this->getJson('/api/v1/movies/discover?page=2')->assertOk()->assertExactJson(['data' => [], 'meta' => ['page' => 2, 'total_pages' => 0, 'total_results' => 0]]);
    }

    public function test_failures_timeouts_and_malformed_responses_are_sanitized(): void
    {
        foreach (['discover', 'genres', 'discover?genre_id=28'] as $endpoint) {
            foreach ([Http::response(['message' => 'private upstream error'], 500), Http::failedConnection('private timeout'), Http::response(['invalid' => true])] as $failure) {
                Http::swap(new Factory);
                Http::preventStrayRequests();
                Http::fake(['*' => $failure]);
                $this->getJson('/api/v1/movies/'.$endpoint)->assertStatus(502)
                    ->assertJsonPath('code', 'MOVIE_FEED_UNAVAILABLE')->assertDontSee('private')->assertDontSee('secret-test-token');
            }
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response([], 429, ['Retry-After' => '60'])]);
            $this->getJson('/api/v1/movies/'.$endpoint)->assertStatus(503)->assertHeader('Retry-After', '60');
        }
    }
}
