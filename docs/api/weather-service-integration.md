# Weather service and location handling — AB#113

`App\Services\WeatherService` is an internal Laravel dependency for the future weighted-v1 recommendation implementation. It has no public route and does not rank movies, persist locations, or change the current recommendation API contract.

## Inputs

Call `current($latitude, $longitude, $city)` with either a coordinate pair or a city. Coordinates take precedence when present, must be finite numeric values, require both values, are bounded to latitude `-90..90` and longitude `-180..180`, and are rounded to two decimal places before cache and provider use. Missing location returns `not_requested`; invalid supplied coordinates or city are rejected at the service boundary.

City support is reusable for a future caller. It uses Open-Meteo geocoding server-side with a nonempty city up to 120 characters. The service requests multiple candidates, matches the requested city name exactly (not as a prefix), and, when supplied after a comma, matches the country/admin qualifier against the provider result. It proceeds only when exactly one candidate matches; zero, malformed, ambiguous, duplicate, rate-limited, or failed responses return weather `unavailable`. For example, `Beirut, Lebanon` matches `Beirut` while excluding `Beirut Air Base`. It never substitutes a different location.

AB#112's published `GET /api/v1/recommendations` contract continues to accept **coordinates only**. A recommendation controller may call `WeatherService::current($latitude, $longitude)` later. City support is intentionally not silently added to that shared public request contract.

## Provider configuration

Configuration lives in `backend/config/services.php`:

```env
OPEN_METEO_WEATHER_URL=https://api.open-meteo.com/v1/forecast
OPEN_METEO_GEOCODING_URL=https://geocoding-api.open-meteo.com/v1/search
```

Only HTTPS URLs on Open-Meteo's trusted weather/geocoding hosts are accepted. No credentials are required or stored. The weather request asks for `current=temperature_2m,weather_code`, Celsius, and UTC timestamps. The city flow adds one bounded geocoding request, separately from AB#112's one-weather-request budget.

## Output contract

Every call returns only normalized weather—not the supplied or resolved location:

```json
{
  "status": "available",
  "condition": "rainy",
  "temperature_c": 14,
  "valid_at": "2026-11-21T15:00:00.000000Z"
}
```

Allowed statuses are `available`, `unavailable`, and `not_requested`. For unavailable/not-requested results, `condition`, `temperature_c`, and `valid_at` are null. Callers must deactivate weighted-v1's weather signal when status is not `available`; they must not invent a condition.

Normalization follows AB#112 exactly: snow codes win first, then rain/thunderstorm codes, then cold at `<=10°C`, hot at `>=30°C`, clear codes, and cloudy codes. Unknown codes, nonfinite temperatures, missing/invalid timestamps, readings older than 60 minutes, and readings more than five minutes in the future are unavailable.

## Resilience and privacy

Provider calls use Laravel's HTTP client with two-second connection/request timeouts and no retries. HTTP failures (including 429), connection failures, malformed JSON, and missing fields fall back to unavailable weather. Successful weather responses are cached for ten minutes by rounded coordinate key; successful city lookups are cached for one hour. A 60-second failure cooldown and cache locks reduce repeated failed/concurrent calls. Coordinates and city text are never logged, stored in the database, or emitted in normalized output.

## Testing

Run from `backend`:

```bash
php artisan test --filter=WeatherServiceTest
php artisan test
```

The unit suite uses `Http::fake()` and a controlled clock; it makes no live provider or database calls. It covers coordinate/city handling, condition mapping and precedence, timestamp validation, provider failures, cache reuse/expiry, and response privacy.
