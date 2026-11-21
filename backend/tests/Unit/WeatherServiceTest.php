<?php

namespace Tests\Unit;

use App\Services\WeatherService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class WeatherServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'services.weather.weather_url' => 'https://api.open-meteo.com/v1/forecast',
            'services.weather.geocoding_url' => 'https://geocoding-api.open-meteo.com/v1/search',
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-21 15:30:00 UTC'));
    }

    public function test_returns_not_requested_without_a_location(): void
    {
        $this->assertSame($this->empty('not_requested'), app(WeatherService::class)->current());
        Http::assertNothingSent();
    }

    public function test_fetches_rounded_coordinates_and_returns_the_normalized_contract(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response($this->weather(2, 14.0), 200)]);

        $result = app(WeatherService::class)->current(33.891, 35.501);

        $this->assertSame('available', $result['status']);
        $this->assertSame('clear', $result['condition']);
        $this->assertSame(14.0, $result['temperature_c']);
        $this->assertSame('2026-11-21T15:30:00.000000Z', $result['valid_at']);
        $this->assertArrayNotHasKey('latitude', $result);
        Http::assertSent(fn (Request $request): bool => $request['latitude'] === 33.89
            && $request['longitude'] === 35.5
            && $request['current'] === 'temperature_2m,weather_code'
            && $request['temperature_unit'] === 'celsius'
            && $request['timezone'] === 'UTC');
    }

    public function test_city_resolution_uses_geocoding_then_weather_without_exposing_coordinates(): void
    {
        Http::fake([
            'geocoding-api.open-meteo.com/*' => Http::response(['results' => [
                $this->city('Beirut', 33.8938, 35.5018, 'Lebanon'),
                $this->city('Beirut Air Base', 33.82, 35.49, 'Lebanon'),
            ]]),
            'api.open-meteo.com/*' => Http::response($this->weather(61, 14), 200),
        ]);

        $result = app(WeatherService::class)->current(city: 'Beirut, Lebanon');

        $this->assertSame('rainy', $result['condition']);
        $this->assertArrayNotHasKey('latitude', $result);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'geocoding-api.open-meteo.com') && $request['name'] === 'Beirut, Lebanon' && $request['count'] === 10);
        Http::assertSentCount(2);
    }

    public function test_invalid_location_input_is_rejected_and_unknown_or_ambiguous_cities_are_unavailable(): void
    {
        $service = app(WeatherService::class);
        foreach ([[null, 35], [91, 35], ['NaN', 35], [33, INF]] as [$latitude, $longitude]) {
            try {
                $service->forCoordinates($latitude, $longitude);
                $this->fail('Invalid coordinate pair was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $service->resolveCity('   ');
    }

    public function test_no_matching_city_falls_back_without_a_weather_call(): void
    {
        Http::fake(['geocoding-api.open-meteo.com/*' => Http::response(['results' => []])]);
        $this->assertSame($this->empty('unavailable'), app(WeatherService::class)->current(city: 'No Such City'));
        Http::assertSentCount(1);

    }

    public function test_ambiguous_and_duplicate_exact_city_matches_are_rejected(): void
    {
        Http::fake(['geocoding-api.open-meteo.com/*' => Http::sequence()
            ->push(['results' => [$this->city('Beirut', 1, 1, 'Lebanon'), $this->city('Beirut', 2, 2, 'Syria')]])
            ->push(['results' => [$this->city('Beirut', 1, 1, 'Lebanon'), $this->city('Beirut', 2, 2, 'Lebanon')]])]);
        $this->assertSame($this->empty('unavailable'), app(WeatherService::class)->current(city: 'Beirut'));
        Http::assertSentCount(1);

        Cache::flush();
        $this->assertSame($this->empty('unavailable'), app(WeatherService::class)->current(city: 'Beirut, Lebanon'));
        Http::assertSentCount(2);
    }

    public function test_city_geocoding_cache_reuses_an_exact_qualified_match(): void
    {
        Http::fake(['geocoding-api.open-meteo.com/*' => Http::response(['results' => [
            $this->city('Beirut', 33.8938, 35.5018, 'Lebanon'),
        ]])]);

        $service = app(WeatherService::class);
        $this->assertSame(['latitude' => 33.89, 'longitude' => 35.5], $service->resolveCity('Beirut, Lebanon'));
        $this->assertSame(['latitude' => 33.89, 'longitude' => 35.5], $service->resolveCity('Beirut, Lebanon'));
        Http::assertSentCount(1);
    }

    public function test_condition_priority_and_temperature_classes_are_exact(): void
    {
        $cases = [
            [71, 35, 'snowy'], [61, 35, 'rainy'], [0, 10, 'cold'], [3, 30, 'hot'], [1, 20, 'clear'], [45, 20, 'cloudy'],
        ];
        $responses = Http::sequence();
        foreach ($cases as [$code, $temperature]) {
            $responses->push($this->weather($code, $temperature));
        }
        Http::fake(['api.open-meteo.com/*' => $responses]);

        foreach ($cases as $index => [$code, $temperature, $condition]) {
            Cache::flush();
            $this->assertSame($condition, app(WeatherService::class)->forCoordinates(10 + $index, 10)['condition']);
        }
    }

    public function test_bad_provider_values_timestamps_and_errors_return_unavailable(): void
    {
        foreach ([
            $this->weather(999, 20), $this->weather(0, 'bad'), ['current' => ['weather_code' => 0, 'temperature_2m' => 20]],
            $this->weather(0, 20, '2026-11-21T14:29:00Z'), $this->weather(0, 20, '2026-11-21T15:36:00Z'),
        ] as $payload) {
            Cache::flush();
            Http::fake(['api.open-meteo.com/*' => Http::response($payload, 200)]);
            $this->assertSame($this->empty('unavailable'), app(WeatherService::class)->forCoordinates(10, 10));
        }

        foreach ([Http::response([], 429), Http::response([], 500), Http::response('not-json', 200), Http::failedConnection('timeout')] as $response) {
            Cache::flush();
            Http::fake(['api.open-meteo.com/*' => $response]);
            $this->assertSame($this->empty('unavailable'), app(WeatherService::class)->forCoordinates(11, 11));
        }
    }

    public function test_cache_reuses_equivalent_rounded_coordinates_and_expires(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response($this->weather(0, 20), 200)]);
        $service = app(WeatherService::class);
        $service->forCoordinates(33.891, 35.501);
        $service->forCoordinates(33.894, 35.504);
        Http::assertSentCount(1);

        $this->travel(11)->minutes();
        $service->forCoordinates(33.891, 35.501);
        Http::assertSentCount(2);
    }

    /** @return array<string, mixed> */
    private function weather(int $code, mixed $temperature, ?string $time = null): array
    {
        return ['current' => ['weather_code' => $code, 'temperature_2m' => $temperature, 'time' => $time ?? now()->utc()->toISOString()]];
    }

    /** @return array<string, float|string> */
    private function city(string $name, float $latitude, float $longitude, string $country): array
    {
        return ['name' => $name, 'latitude' => $latitude, 'longitude' => $longitude, 'country' => $country];
    }

    /** @return array{status: string, condition: null, temperature_c: null, valid_at: null} */
    private function empty(string $status): array
    {
        return ['status' => $status, 'condition' => null, 'temperature_c' => null, 'valid_at' => null];
    }
}
