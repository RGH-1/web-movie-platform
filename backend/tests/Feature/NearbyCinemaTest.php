<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NearbyCinemaTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/cinemas/nearby';

    private const QUERY = '?lat=33.8938&lng=35.5018&radius=10000';

    private const PROVIDER = 'https://api.geoapify.com/v2/places';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.geoapify.key' => 'test-geoapify-key']);
        Http::preventStrayRequests();
    }

    public function test_signed_in_search_normalizes_cinemas_and_sends_the_search_circle(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([
            self::PROVIDER.'*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'geometry' => ['type' => 'Point', 'coordinates' => [35.502, 33.894]],
                        'properties' => [
                            'place_id' => 'test-cinema',
                            'name' => 'Test Cinema',
                            'formatted' => 'Cinema Street, Beirut',
                            'provider_only_field' => 'ignored',
                        ],
                    ],
                    [
                        'geometry' => ['type' => 'Point', 'coordinates' => [35.5, 33.9]],
                        'properties' => ['place_id' => 'second-cinema', 'name' => 'Second Cinema'],
                    ],
                    [
                        'geometry' => ['type' => 'Point', 'coordinates' => [181, 33.894]],
                        'properties' => ['place_id' => 'invalid-cinema', 'name' => 'Invalid Cinema'],
                    ],
                ],
            ]),
        ]);

        $this->getJson(self::ENDPOINT.self::QUERY)->assertOk()->assertExactJson([
            'data' => [
                [
                    'id' => 'test-cinema',
                    'name' => 'Test Cinema',
                    'address' => 'Cinema Street, Beirut',
                    'latitude' => 33.894,
                    'longitude' => 35.502,
                ],
                [
                    'id' => 'second-cinema',
                    'name' => 'Second Cinema',
                    'address' => null,
                    'latitude' => 33.9,
                    'longitude' => 35.5,
                ],
            ],
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return $request->method() === 'GET' && $query === [
                'categories' => 'entertainment.cinema',
                'filter' => 'circle:35.5018,33.8938,10000',
                'bias' => 'proximity:35.5018,33.8938',
                'limit' => '20',
                'apiKey' => 'test-geoapify-key',
            ];
        });
    }

    public function test_guest_search_is_rejected_without_using_the_provider(): void
    {
        Http::fake();

        $this->getJson(self::ENDPOINT.self::QUERY)->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_missing_and_invalid_parameters_are_rejected_without_using_the_provider(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake();

        $this->getJson(self::ENDPOINT)->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng', 'radius']);

        $this->getJson(self::ENDPOINT.'?lat=90.01&lng=-180.01&radius=50001')->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng', 'radius']);

        $this->getJson(self::ENDPOINT.'?lat=north&lng=east&radius=0')->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng', 'radius']);

        Http::assertNothingSent();
    }

    public function test_empty_provider_results_are_a_successful_search(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([
            self::PROVIDER.'*' => Http::response(['type' => 'FeatureCollection', 'features' => []]),
        ]);

        $this->getJson(self::ENDPOINT.'?lat=-90&lng=180&radius=50000')
            ->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_missing_provider_configuration_returns_unavailable(): void
    {
        $this->actingAs(User::factory()->create());
        config(['services.geoapify.key' => '']);
        Http::fake();

        $this->getJson(self::ENDPOINT.self::QUERY)->assertServiceUnavailable();

        Http::assertNothingSent();
    }

    public function test_provider_failures_are_reported_without_exposing_provider_details(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([
            self::PROVIDER.'*' => Http::sequence()
                ->push(['message' => 'internal provider error for test-geoapify-key'], 429)
                ->push(['unexpected' => 'invalid provider body']),
        ]);

        foreach (['test-geoapify-key', 'invalid provider body'] as $privateDetail) {
            $this->getJson(self::ENDPOINT.self::QUERY)
                ->assertServiceUnavailable()
                ->assertJsonPath('message', 'Nearby cinema search is temporarily unavailable.')
                ->assertDontSee($privateDetail);
        }
    }

    public function test_provider_connection_failure_returns_unavailable(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([self::PROVIDER.'*' => Http::failedConnection('test-geoapify-key')]);

        $this->getJson(self::ENDPOINT.self::QUERY)
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'Nearby cinema search is temporarily unavailable.')
            ->assertDontSee('test-geoapify-key');
    }
}
