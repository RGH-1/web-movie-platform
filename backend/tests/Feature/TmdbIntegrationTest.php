<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TmdbIntegrationTest extends TestCase
{
    public function test_it_returns_movie_search_results(): void
    {
        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response([
                'results' => [
                    [
                        'id' => 157336,
                        'title' => 'Interstellar',
                        'poster_path' => '/test-poster.jpg',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson(
            '/api/integration/tmdb/search?query=Interstellar'
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.id', 157336)
            ->assertJsonPath('results.0.title', 'Interstellar')
            ->assertJsonPath(
                'results.0.poster_path',
                '/test-poster.jpg'
            );

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request['query'] === 'Interstellar'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer fake-test-token'
                );
        });

        Http::assertSentCount(1);
    }

    public function test_it_returns_an_empty_results_array(): void
    {
        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response([
                'results' => [],
            ], 200),
        ]);

        $this->getJson('/api/integration/tmdb/search?query=NoMatchingMovie')
            ->assertOk()
            ->assertExactJson(['results' => []]);
    }

    public function test_it_rejects_a_missing_search_query(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->getJson('/api/integration/tmdb/search')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['query']);

        Http::assertNothingSent();
    }

    public function test_it_returns_a_controlled_error_when_tmdb_fails(): void
    {
        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response([], 500),
        ]);

        $this->getJson('/api/integration/tmdb/search?query=Interstellar')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'message' => 'Movie search is unavailable. Please try again later.',
            ]);
    }
}
