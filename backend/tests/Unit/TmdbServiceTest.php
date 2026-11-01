<?php

namespace Tests\Unit;

use App\Services\TmdbService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TmdbServiceTest extends TestCase
{
    public function test_it_returns_a_successful_tmdb_response(): void
    {
        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::fake([
            'api.themoviedb.org/*' => Http::response([
                'id' => 157336,
                'title' => 'Interstellar',
            ], 200),
        ]);

        $service = new TmdbService();

        $response = $service->get('/movie/157336');

        $this->assertEquals(200, $response->status());
        $this->assertEquals('Interstellar', $response->json('title'));
    }

    public function test_it_handles_tmdb_http_errors(): void
    {
        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::fake([
            'api.themoviedb.org/*' => Http::response([
                'status_message' => 'The resource could not be found.',
            ], 404),
        ]);

        $service = new TmdbService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'The movie information service returned an error.'
        );

        $service->get('/movie/999999999');
    }

    public function test_it_handles_connection_failures(): void
    {
        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::fake([
            'api.themoviedb.org/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException(
                    'Simulated connection failure'
                );
            },
        ]);

        $service = new TmdbService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Unable to connect to the movie information service.'
        );

        $service->get('/movie/157336');
    }

    public function test_it_rejects_missing_tmdb_credentials(): void
    {
        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'TMDB API configuration is missing.'
        );

        new TmdbService();
    }


    public function test_it_sends_query_parameters_and_authentication(): void
    {
        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::fake([
            'api.themoviedb.org/*' => Http::response([
                'results' => [],
            ], 200),
        ]);

        $service = new TmdbService();

        $service->get('/search/movie', [
            'query' => 'Interstellar',
            'language' => 'en-US',
        ]);

        Http::assertSent(function ($request) {
            return str_starts_with(
                $request->url(),
                'https://api.themoviedb.org/3/search/movie?'
            )
                && $request['query'] === 'Interstellar'
                && $request['language'] === 'en-US'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer fake-test-token'
                );
        });
    }
}
