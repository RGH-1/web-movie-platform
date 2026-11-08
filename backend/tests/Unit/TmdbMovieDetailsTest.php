<?php

namespace Tests\Unit;

use App\Exceptions\TmdbRequestException;
use App\Services\TmdbService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class TmdbMovieDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::preventStrayRequests();
    }

    public function test_it_returns_normalized_details_with_one_authenticated_request(): void
    {
        $this->fakeMovie($this->moviePayload());

        $movie = (new TmdbService())->getMovieDetails(157336);

        $this->assertSame([
            'tmdb_id' => 157336,
            'title' => 'Interstellar',
            'overview' => 'A team travels through space.',
            'release_date' => '2014-11-05',
            'runtime_minutes' => 169,
            'genres' => [
                ['tmdb_id' => 878, 'name' => 'Science Fiction'],
            ],
            'poster_url' => 'https://image.tmdb.org/t/p/w342/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w780/backdrop.jpg',
            'vote_average' => 8.4,
            'cast' => [
                [
                    'tmdb_person_id' => 10297,
                    'name' => 'Matthew McConaughey',
                    'character' => 'Cooper',
                    'profile_url' => 'https://image.tmdb.org/t/p/w185/person.jpg',
                ],
            ],
            'director' => 'Christopher Nolan',
            'trailer' => [
                'site' => 'YouTube',
                'key' => 'zSWdZVtXT7E',
            ],
        ], $movie);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_starts_with(
                    $request->url(),
                    'https://api.themoviedb.org/3/movie/157336?'
                )
                && $request['language'] === 'en-US'
                && $request['append_to_response'] === 'credits,videos'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer fake-test-token'
                );
        });

        Http::assertSentCount(1);

        $this->assertStringNotContainsString(
            'fake-test-token',
            json_encode($movie, JSON_THROW_ON_ERROR)
        );
    }

    public function test_it_orders_and_limits_the_principal_cast(): void
    {
        $payload = $this->moviePayload();
        $cast = [];

        for ($order = 9; $order >= 0; $order--) {
            $cast[] = [
                'id' => 100 + $order,
                'name' => "Actor {$order}",
                'order' => $order,
            ];
        }

        $cast[] = ['id' => 999, 'name' => null, 'order' => -1];
        $payload['credits']['cast'] = $cast;

        $this->fakeMovie($payload);
        $movie = (new TmdbService())->getMovieDetails(157336);

        $this->assertCount(8, $movie['cast']);
        $this->assertSame(
            range(100, 107),
            array_column($movie['cast'], 'tmdb_person_id')
        );
        $this->assertNull($movie['cast'][0]['character']);
        $this->assertNull($movie['cast'][0]['profile_url']);
    }

    public function test_missing_optional_sections_return_empty_values(): void
    {
        $this->fakeMovie([
            'id' => 157336,
            'title' => 'Interstellar',
        ]);

        $movie = (new TmdbService())->getMovieDetails(157336);

        $this->assertSame([], $movie['cast']);
        $this->assertSame([], $movie['genres']);
        $this->assertNull($movie['director']);
        $this->assertNull($movie['trailer']);
        $this->assertNull($movie['overview']);
        $this->assertNull($movie['release_date']);
        $this->assertNull($movie['runtime_minutes']);
        $this->assertNull($movie['vote_average']);
        $this->assertNull($movie['poster_url']);
        $this->assertNull($movie['backdrop_url']);

        Http::assertSentCount(1);
    }

    public function test_unusable_optional_values_do_not_break_the_movie(): void
    {
        $payload = $this->moviePayload();
        $payload['credits'] = null;
        $payload['videos'] = 'unavailable';
        $payload['genres'] = null;
        $payload['overview'] = ' ';
        $payload['release_date'] = '2026-02-30';
        $payload['runtime'] = 0;
        $payload['vote_count'] = 0;
        $payload['poster_path'] = '';
        $payload['backdrop_path'] = null;

        $this->fakeMovie($payload);
        $movie = (new TmdbService())->getMovieDetails(157336);

        $this->assertSame([], $movie['cast']);
        $this->assertSame([], $movie['genres']);
        $this->assertNull($movie['director']);
        $this->assertNull($movie['trailer']);
        $this->assertNull($movie['overview']);
        $this->assertNull($movie['release_date']);
        $this->assertNull($movie['runtime_minutes']);
        $this->assertNull($movie['vote_average']);
        $this->assertNull($movie['poster_url']);
        $this->assertNull($movie['backdrop_url']);
    }

    #[DataProvider('trailerCases')]
    public function test_it_selects_a_suitable_trailer(
        array $videos,
        ?string $expectedKey,
    ): void {
        $payload = $this->moviePayload();
        $payload['videos']['results'] = $videos;

        $this->fakeMovie($payload);
        $movie = (new TmdbService())->getMovieDetails(157336);

        $expected = $expectedKey === null
            ? null
            : ['site' => 'YouTube', 'key' => $expectedKey];

        $this->assertSame($expected, $movie['trailer']);
    }

    public static function trailerCases(): array
    {
        $unofficial = [
            'site' => 'YouTube',
            'type' => 'Trailer',
            'key' => 'aaaaaaaaaaa',
            'official' => false,
        ];

        $official = [
            'site' => 'YouTube',
            'type' => 'Trailer',
            'key' => 'bbbbbbbbbbb',
            'official' => true,
        ];

        return [
            'prefers official' => [
                [$unofficial, $official],
                'bbbbbbbbbbb',
            ],
            'falls back to unofficial' => [
                [$unofficial],
                'aaaaaaaaaaa',
            ],
            'no suitable video' => [
                [
                    array_replace($official, ['site' => 'Vimeo']),
                    array_replace($official, ['type' => 'Teaser']),
                    array_replace($official, ['key' => 'invalid']),
                ],
                null,
            ],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_it_rejects_an_unusable_base_payload(mixed $payload): void
    {
        Http::fake([
            'api.themoviedb.org/*' => Http::response($payload, 200),
        ]);

        $this->expectException(UnexpectedValueException::class);

        (new TmdbService())->getMovieDetails(157336);
    }

    public static function invalidPayloads(): array
    {
        return [
            'invalid JSON' => ['not-json'],
            'missing ID' => [['title' => 'Interstellar']],
            'wrong ID' => [['id' => 1, 'title' => 'Interstellar']],
            'missing title' => [['id' => 157336]],
            'blank title' => [['id' => 157336, 'title' => ' ']],
        ];
    }

    #[DataProvider('upstreamFailures')]
    public function test_it_preserves_upstream_error_information(
        int $status,
        ?string $retryAfter,
    ): void {
        $headers = $retryAfter === null
            ? []
            : ['Retry-After' => $retryAfter];

        Http::fake([
            'api.themoviedb.org/*' => Http::response([
                'status_message' => 'private-provider-error',
            ], $status, $headers),
        ]);

        try {
            (new TmdbService())->getMovieDetails(157336);
            $this->fail('Expected a TMDB request exception.');
        } catch (TmdbRequestException $exception) {
            $this->assertSame($status, $exception->upstreamStatus);
            $this->assertSame($retryAfter, $exception->retryAfter);
            $this->assertSame(
                'The movie information service returned an error.',
                $exception->getMessage()
            );
            $this->assertStringNotContainsString(
                'private-provider-error',
                $exception->getMessage()
            );
        }

        Http::assertSentCount(1);
    }

    public static function upstreamFailures(): array
    {
        return [
            'missing movie' => [404, null],
            'rate limited' => [429, '120'],
            'provider failure' => [500, null],
        ];
    }

    public function test_it_reports_connection_failures_without_an_upstream_status(): void
    {
        Http::fake([
            'api.themoviedb.org/*' => function () {
                throw new ConnectionException('Simulated connection failure');
            },
        ]);

        try {
            (new TmdbService())->getMovieDetails(157336);
            $this->fail('Expected a TMDB request exception.');
        } catch (TmdbRequestException $exception) {
            $this->assertNull($exception->upstreamStatus);
            $this->assertNull($exception->retryAfter);
            $this->assertSame(
                'Unable to connect to the movie information service.',
                $exception->getMessage()
            );
        }
    }

    #[DataProvider('invalidMovieIds')]
    public function test_invalid_ids_do_not_make_http_requests(int $movieId): void
    {
        Http::fake();

        try {
            (new TmdbService())->getMovieDetails($movieId);
            $this->fail('Expected an invalid movie ID exception.');
        } catch (InvalidArgumentException $exception) {
            Http::assertNothingSent();
        }
    }

    public static function invalidMovieIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'too large' => [2147483648],
        ];
    }

    private function fakeMovie(array $payload): void
    {
        Http::fake([
            'api.themoviedb.org/3/movie/157336*' => Http::response($payload, 200),
        ]);
    }

    private function moviePayload(): array
    {
        return [
            'id' => 157336,
            'title' => 'Interstellar',
            'overview' => 'A team travels through space.',
            'release_date' => '2014-11-05',
            'runtime' => 169,
            'genres' => [
                ['id' => 878, 'name' => 'Science Fiction'],
            ],
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'vote_average' => 8.4,
            'vote_count' => 100,
            'credits' => [
                'cast' => [
                    [
                        'id' => 10297,
                        'name' => 'Matthew McConaughey',
                        'character' => 'Cooper',
                        'profile_path' => '/person.jpg',
                        'order' => 0,
                    ],
                ],
                'crew' => [
                    ['name' => 'Someone Else', 'job' => 'Producer'],
                    ['name' => 'Christopher Nolan', 'job' => 'Director'],
                ],
            ],
            'videos' => [
                'results' => [
                    [
                        'site' => 'YouTube',
                        'type' => 'Trailer',
                        'key' => 'zSWdZVtXT7E',
                        'official' => true,
                    ],
                ],
            ],
        ];
    }
}
