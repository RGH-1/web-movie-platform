# TMDB Integration Verification

Azure Boards work item: AB#15

## Scope

Verify movie search and movie details through the existing Laravel
TmdbService. This task does not add public movie API endpoints or
a frontend interface.

## Local setup

From the backend directory, install the locked dependencies:

```bash
composer install
```

If no local .env exists, copy .env.example to .env and generate
the application key. Do not overwrite an existing local environment.

If you do not have a TMDB API Read Access Token, follow
[Step 8: TMDB API Configuration](../development-setup.md#8-tmdb-api-configuration)
in the development setup guide.

For live checks, configure these values in backend/.env:

```dotenv
TMDB_BASE_URL=https://api.themoviedb.org/3
TMDB_API_READ_TOKEN=your_read_access_token
```

Keep the actual token private. Do not commit .env.

After changing configuration:

```bash
php artisan config:clear
```

## Automated tests

From backend:

```bash
php artisan test --filter=TmdbServiceTest
php artisan test
```

The TMDB tests use simulated HTTP responses and fake credentials.
They cover:

- Successful search results and expected movie fields.
- Successful movie details and the requested movie endpoint.
- Search query parameters and bearer authentication.
- Unsuccessful upstream HTTP responses.
- Connection failures.
- Missing credentials.

Verification result: 6 TMDB tests passed; 8 backend tests passed.

These tests verify application behavior with controlled responses.
They do not establish live TMDB availability or credential validity.

## Live search and details checks

From backend:

```bash
php artisan tinker
```

Run inside Tinker:

```php
$service = app(\App\Services\TmdbService::class);

$search = $service->get('/search/movie', [
    'query' => 'Interstellar',
    'language' => 'en-US',
]);

$search->status();

collect($search->json('results'))
    ->take(3)
    ->map(fn ($movie) => \Illuminate\Support\Arr::only(
        $movie,
        ['id', 'title', 'release_date']
    ))
    ->all();

$details = $service->get('/movie/157336');

$details->status();

\Illuminate\Support\Arr::only(
    $details->json(),
    ['id', 'title', 'overview', 'release_date', 'runtime']
);
```

Observed results:

- Search returned HTTP 200 and included Interstellar, ID 157336.
- Details returned HTTP 200 with ID 157336, title Interstellar,
  a nonempty overview, release date 2014-11-04, and runtime 169.
- Live API data may change; these observations are not fixed
  assertions for automated tests.

## Connection troubleshooting

An initial live request failed with cURL error 28: DNS resolution timed out.

An independent nslookup also timed out against the configured
DNS server. A lookup through an alternate DNS server returned
TMDB addresses. Subsequent Laravel search and details requests
succeeded.

Investigate network and DNS failures before changing application code. Do not disable TLS certificate verification.