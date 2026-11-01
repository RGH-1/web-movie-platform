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

        Http::preventStrayRequests();

        Http::fake([
            'api.themoviedb.org/*' => Http::response([
                'id' => 157336,
                'title' => 'Interstellar',
                'overview' => 'A team travels through space.',
                'release_date' => '2014-11-05',
                'runtime' => 169,
            ], 200),
        ]);

        $service = new TmdbService();

        $response = $service->get('/movie/157336');

        $this->assertEquals(200, $response->status());
        $this->assertSame(157336, $response->json('id'));
        $this->assertEquals('Interstellar', $response->json('title'));
        $this->assertSame('A team travels through space.', $response->json('overview'));
        $this->assertSame('2014-11-05', $response->json('release_date'));
        $this->assertSame(169, $response->json('runtime'));

        Http::assertSent(function ($request){
            return $request->method() === 'GET' && $request->url() === 'https://api.themoviedb.org/3/movie/157336';
        });

        Http::assertSentCount(1);
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

    public function test_it_returns_movie_search_results():void {
        config([
           'services.tmdb.base_url'=>'https://api.themoviedb.org/3',
           'services.tmdb.token'=>'fake-test-token',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'api.themoviedb.org/3/search/movie*'=>Http::response([
                'page'=>1,
                'results'=>[
                    [
                        'id'=>157336,
                        'title'=>'Interstellar',
                        'release_date'=>'2014-11-05',
                        'poster_path'=>'/test-poster.jpg',
                    ],
                ],
                'total_results'=>1,
                'total_pages'=>1,
            ],200),
        ]);

        $service = new TmdbService();

        $response = $service->get('/search/movie',['query'=>'Interstellar','language'=>'en-US']);

        $this->assertEquals(200, $response->status());
        $this->assertCount(1, $response->json('results'));
        $this->assertSame(157336, $response->json('results.0.id'));
        $this->assertSame('Interstellar', $response->json('results.0.title'));
        $this->assertSame('2014-11-05', $response->json('results.0.release_date'));
        $this->assertSame('/test-poster.jpg', $response->json('results.0.poster_path'));

        Http::assertSentCount(1);

    }


}
