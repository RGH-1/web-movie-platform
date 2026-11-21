<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** Optional current-weather adapter for weighted-v1 recommendations. */
class WeatherService
{
    private const WEATHER_TTL_SECONDS = 600;

    private const GEOCODE_TTL_SECONDS = 3600;

    private const FAILURE_TTL_SECONDS = 60;

    private const SNOWY_CODES = [71, 73, 75, 77, 85, 86];

    private const RAINY_CODES = [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82, 95, 96, 99];

    private const CLEAR_CODES = [0, 1, 2];

    private const CLOUDY_CODES = [3, 45, 48];

    /**
     * Resolve an optional location without exposing it in the normalized result.
     * Coordinates take precedence when both a coordinate pair and city are supplied.
     *
     * @return array{status: string, condition: string|null, temperature_c: float|null, valid_at: string|null}
     */
    public function current(mixed $latitude = null, mixed $longitude = null, mixed $city = null): array
    {
        if ($latitude !== null || $longitude !== null) {
            return $this->forCoordinates($latitude, $longitude);
        }

        if ($city === null) {
            return $this->notRequested();
        }

        $coordinates = $this->resolveCity($city);

        return $coordinates === null
            ? $this->unavailable()
            : $this->forCoordinates($coordinates['latitude'], $coordinates['longitude']);
    }

    /** @return array{status: string, condition: string|null, temperature_c: float|null, valid_at: string|null} */
    public function forCoordinates(mixed $latitude, mixed $longitude): array
    {
        [$latitude, $longitude] = $this->coordinates($latitude, $longitude);
        $key = 'weather:v1:'.number_format($latitude, 2, '.', '').':'.number_format($longitude, 2, '.', '');

        if (Cache::get($key.':failure') === true) {
            return $this->unavailable();
        }

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->withLock($key, function () use ($key, $latitude, $longitude): array {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }

            $result = $this->requestWeather($latitude, $longitude);
            if ($result['status'] === 'available') {
                Cache::put($key, $result, self::WEATHER_TTL_SECONDS);
            } else {
                Cache::put($key.':failure', true, self::FAILURE_TTL_SECONDS);
            }

            return $result;
        });
    }

    /** @return array{latitude: float, longitude: float}|null */
    public function resolveCity(mixed $city): ?array
    {
        if (! is_string($city) || trim($city) === '' || mb_strlen(trim($city)) > 120) {
            throw new InvalidArgumentException('City must be a nonempty string up to 120 characters.');
        }

        $city = trim($city);
        [$requestedName, $qualifier] = $this->cityParts($city);
        $key = 'weather:geocode:v1:'.hash('sha256', mb_strtolower($city));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if (Cache::get($key.':failure') === true) {
            return null;
        }

        return $this->withLock($key, function () use ($city, $requestedName, $qualifier, $key): ?array {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }

            try {
                $response = Http::acceptJson()
                    ->connectTimeout(2)
                    ->timeout(2)
                    ->get($this->geocodingUrl(), ['name' => $city, 'count' => 10, 'format' => 'json', 'language' => 'en']);
                $result = $response->successful() ? $response->json('results') : null;
            } catch (ConnectionException) {
                $result = null;
            } catch (Throwable) {
                $result = null;
            }

            if (! is_array($result) || ! array_is_list($result)) {
                Cache::put($key.':failure', true, self::FAILURE_TTL_SECONDS);

                return null;
            }

            $matches = array_values(array_filter(
                $result,
                fn (mixed $candidate): bool => is_array($candidate) && $this->matchesCity($candidate, $requestedName, $qualifier),
            ));
            if (count($matches) !== 1) {
                Cache::put($key.':failure', true, self::FAILURE_TTL_SECONDS);

                return null;
            }

            try {
                [$latitude, $longitude] = $this->coordinates($matches[0]['latitude'] ?? null, $matches[0]['longitude'] ?? null);
            } catch (InvalidArgumentException) {
                Cache::put($key.':failure', true, self::FAILURE_TTL_SECONDS);

                return null;
            }

            $coordinates = ['latitude' => $latitude, 'longitude' => $longitude];
            Cache::put($key, $coordinates, self::GEOCODE_TTL_SECONDS);

            return $coordinates;
        });
    }

    /** @return array{status: string, condition: string|null, temperature_c: float|null, valid_at: string|null} */
    private function requestWeather(float $latitude, float $longitude): array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(2)
                ->get($this->weatherUrl(), [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'current' => 'temperature_2m,weather_code',
                    'temperature_unit' => 'celsius',
                    'timezone' => 'UTC',
                ]);
            if (! $response->successful()) {
                return $this->unavailable();
            }

            $current = $response->json('current');
            if (! is_array($current)) {
                return $this->unavailable();
            }

            return $this->normalize($current['weather_code'] ?? null, $current['temperature_2m'] ?? null, $current['time'] ?? null);
        } catch (ConnectionException) {
            return $this->unavailable();
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /** @return array{status: string, condition: string|null, temperature_c: float|null, valid_at: string|null} */
    private function normalize(mixed $code, mixed $temperature, mixed $time): array
    {
        if (! is_int($code) || ! is_numeric($temperature) || ! is_finite((float) $temperature) || ! is_string($time)) {
            return $this->unavailable();
        }
        $recognized = array_merge(self::SNOWY_CODES, self::RAINY_CODES, self::CLEAR_CODES, self::CLOUDY_CODES);
        if (! in_array($code, $recognized, true)) {
            return $this->unavailable();
        }

        try {
            $validAt = Carbon::parse($time, 'UTC')->utc();
        } catch (Throwable) {
            return $this->unavailable();
        }
        $now = now()->utc();
        if ($validAt->lt($now->copy()->subMinutes(60)) || $validAt->gt($now->copy()->addMinutes(5))) {
            return $this->unavailable();
        }

        $condition = match (true) {
            in_array($code, self::SNOWY_CODES, true) => 'snowy',
            in_array($code, self::RAINY_CODES, true) => 'rainy',
            (float) $temperature <= 10 => 'cold',
            (float) $temperature >= 30 => 'hot',
            in_array($code, self::CLEAR_CODES, true) => 'clear',
            default => 'cloudy',
        };

        return ['status' => 'available', 'condition' => $condition, 'temperature_c' => (float) $temperature, 'valid_at' => $validAt->toISOString()];
    }

    /** @return array{0: float, 1: float} */
    private function coordinates(mixed $latitude, mixed $longitude): array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude) || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)) {
            throw new InvalidArgumentException('Latitude and longitude must be finite numeric values together.');
        }
        $latitude = round((float) $latitude, 2);
        $longitude = round((float) $longitude, 2);
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Coordinates are outside the supported range.');
        }

        return [$latitude, $longitude];
    }

    /** @return array{0: string, 1: string|null} */
    private function cityParts(string $city): array
    {
        $parts = array_map('trim', explode(',', $city, 2));
        $name = $this->normalizeLocationText($parts[0]);
        $qualifier = isset($parts[1]) ? $this->normalizeLocationText($parts[1]) : null;
        if ($name === '' || $qualifier === '') {
            throw new InvalidArgumentException('City qualifiers must not be blank.');
        }

        return [$name, $qualifier];
    }

    /** @param array<string, mixed> $candidate */
    private function matchesCity(array $candidate, string $requestedName, ?string $qualifier): bool
    {
        if (! is_string($candidate['name'] ?? null) || $this->normalizeLocationText($candidate['name']) !== $requestedName) {
            return false;
        }
        if ($qualifier === null) {
            return true;
        }

        foreach (['country', 'country_code', 'admin1'] as $field) {
            if (is_string($candidate[$field] ?? null) && $this->normalizeLocationText($candidate[$field]) === $qualifier) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLocationText(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($value))));
    }

    /** @return array{status: string, condition: null, temperature_c: null, valid_at: null} */
    private function unavailable(): array
    {
        return ['status' => 'unavailable', 'condition' => null, 'temperature_c' => null, 'valid_at' => null];
    }

    /** @return array{status: string, condition: null, temperature_c: null, valid_at: null} */
    private function notRequested(): array
    {
        return ['status' => 'not_requested', 'condition' => null, 'temperature_c' => null, 'valid_at' => null];
    }

    private function weatherUrl(): string
    {
        return $this->trustedUrl((string) config('services.weather.weather_url'));
    }

    private function geocodingUrl(): string
    {
        return $this->trustedUrl((string) config('services.weather.geocoding_url'));
    }

    private function trustedUrl(string $url): string
    {
        $parts = parse_url($url);
        $hosts = ['api.open-meteo.com', 'geocoding-api.open-meteo.com'];
        if (($parts['scheme'] ?? null) !== 'https' || ! in_array($parts['host'] ?? null, $hosts, true)) {
            throw new InvalidArgumentException('Weather provider URL must use a trusted Open-Meteo HTTPS host.');
        }

        return $url;
    }

    private function withLock(string $key, callable $callback): mixed
    {
        try {
            $lock = Cache::lock($key.':lock', 5);
            if ($lock->get()) {
                try {
                    return $callback();
                } finally {
                    $lock->release();
                }
            }
        } catch (Throwable) {
            // Array/file cache drivers and lock contention still fall back safely.
        }

        return $callback();
    }
}
