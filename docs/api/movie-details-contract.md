# Movie details API contract

Parent story: View Movie Details  
Work item: AB#50 — F1 — Define the movie details API contract  
Status: Proposed for team review

## Endpoint

`GET /api/v1/movies/{tmdbId}`

This endpoint is public. Send `Accept: application/json`. The request
takes no body or query parameters. Use the `tmdb_id` from a
[movie search result](movie-search-contract.md) as the path parameter.

Example:

```http
GET /api/v1/movies/157336
Accept: application/json
```

The path segment must contain digits. The value must be a positive
integer in the range `1` through `2147483647`, matching TMDB's
signed 32-bit movie ID parameter. Validate the value before calling TMDB.

## Successful response

Return `200 OK` with one movie object under `data`. Every field listed
below is present. Use `null` for unavailable scalar values or a
missing trailer, and `[]` for an empty genre or cast list.

```json
{
  "data": {
    "tmdb_id": 157336,
    "title": "Interstellar",
    "overview": "A team travels beyond Earth in search of a new home.",
    "release_date": "2014-11-05",
    "runtime_minutes": 169,
    "genres": [
      { "tmdb_id": 878, "name": "Science Fiction" },
      { "tmdb_id": 12, "name": "Adventure" }
    ],
    "poster_url": "https://image.tmdb.org/t/p/w342/example-poster.jpg",
    "backdrop_url": "https://image.tmdb.org/t/p/w780/example-backdrop.jpg",
    "vote_average": 8.4,
    "cast": [
      {
        "tmdb_person_id": 10297,
        "name": "Matthew McConaughey",
        "character": "Cooper",
        "profile_url": null
      },
      {
        "tmdb_person_id": 1813,
        "name": "Anne Hathaway",
        "character": "Brand",
        "profile_url": null
      }
    ],
    "director": "Christopher Nolan",
    "trailer": {
      "site": "YouTube",
      "key": "zSWdZVtXT7E"
    }
  }
}
```

The example is illustrative. Image filenames are placeholders.

| Field | Type | Meaning |
| --- | --- | --- |
| `tmdb_id` | Integer | TMDB movie ID. Matches the ID in search results and the requested ID. |
| `title` | String | Non-empty display title. |
| `overview` | String or `null` | Synopsis. Blank or missing text becomes `null`. |
| `release_date` | String or `null` | Date in `YYYY-MM-DD` format. |
| `runtime_minutes` | Integer or `null` | Runtime in minutes. Missing or nonpositive values become `null`. |
| `genres` | Array | Each entry has an integer `tmdb_id` and a string `name`. |
| `poster_url` | String or `null` | Full HTTPS poster URL. Same format as movie search. |
| `backdrop_url` | String or `null` | Full HTTPS backdrop URL. |
| `vote_average` | Number or `null` | TMDB rating on a 0–10 scale. Use `null` when no votes or usable rating are available. |
| `cast` | Array | Up to eight principal cast members in TMDB billing order. |
| `director` | String or `null` | First named crew member credited as `Director` in TMDB crew order. |
| `trailer` | Object or `null` | One playable YouTube trailer. The object has `site` and `key` string fields. |

Each cast entry has an integer `tmdb_person_id`, a string `name`, and
two nullable strings, `character` and `profile_url`. Use `null` for a
missing character name or profile image. Build all image URLs in Laravel.

For the trailer, consider videos whose `site` is `YouTube`, whose `type`
is `Trailer`, and whose `key` is a valid YouTube video key. Pick the
first official match in TMDB response order. If there is no official
match, pick the first eligible match. Return `null` if none qualify.

React can hide the trailer player when `trailer` is `null`, display an
image placeholder when a URL is `null`, and hide a cast or genres
section when its list is empty. A movie with missing optional
information still returns `200`.


## Error responses

| HTTP status | Condition | Frontend behavior |
| --- | --- | --- |
| `404` | A numeric ID passes validation but TMDB confirms the movie does not exist. A nonnumeric path segment also returns a routing `404`. | Show the movie-not-found state. Handle either `404` by status. |
| `422` | The numeric path segment is zero or outside the supported ID range. Laravel rejects it before calling TMDB. | Show an invalid movie link message. |
| `502` | TMDB connection or timeout failure, an unexpected upstream response, or an unusable movie payload. | Show that details are temporarily unavailable and offer a retry. |
| `503` | TMDB returns `429` for the application's shared upstream access. | Wait for `Retry-After` before offering a retry. |
| `500` | Required server configuration is missing. | Show a generic error. |

Example `404` for a movie that does not exist:

```json
{
  "message": "Movie not found.",
  "code": "MOVIE_NOT_FOUND"
}
```

A nonnumeric segment is rejected by the router and may use Laravel's
default `404` body.

Example `422` for `GET /api/v1/movies/0`:

```json
{
  "message": "The TMDB ID must be a positive integer in the supported range.",
  "errors": {
    "tmdbId": ["The TMDB ID must be a positive integer in the supported range."]
  }
}
```

Example `502`:

```json
{
  "message": "Movie details are temporarily unavailable.",
  "code": "MOVIE_DETAILS_UNAVAILABLE"
}
```

Example `503` for an upstream `429`. The response includes
`Retry-After: 30` if there is no usable upstream retry interval.

```json
{
  "message": "Movie details are busy. Please try again shortly.",
  "code": "MOVIE_DETAILS_RATE_LIMITED"
}
```

Use the same retry policy as movie search. Convert a usable upstream
`Retry-After` to a positive delay in seconds. Otherwise use 30 seconds.
Avoid immediate automatic retries. Keep TMDB credentials, raw upstream
error bodies, and internal exception details out of client responses.

## Implementation handoff

- Register `/movies/{tmdbId}` in the existing `v1` route group, outside
  `auth:sanctum`. Use `whereNumber('tmdbId')` so the details route cannot
  capture `/movies/search`.
- Validate the route value, rather than a query-string field named
  `tmdbId`. A Form Request can take the value from
  `$this->route('tmdbId')` and apply `required`, `integer`, and
  `between:1,2147483647`.
- Call the existing `TmdbService` with the validated ID. Use
  `language=en-US` to match search. Append `credits` and `videos`
  to retrieve the three parts in one TMDB request.

```php
$movie = $tmdbService->getMovieDetails($tmdbId);
```

- Use `TmdbRequestException` to inspect upstream status and retry
  information. Apply the error responses and retry policy defined above.
- Map the result to this contract. A missing or mismatched movie ID or
  a missing title makes the base payload unusable and returns `502`.
  A usable base movie with missing credits, videos, or optional
  metadata uses the documented empty values.
- Use the existing Axios client. Its base URL includes `/api`, so the
  request path begins with `/v1`. Pass the selected search result's
  `tmdb_id` to the details page.

```js
import api from './api';

export async function getMovieDetails(tmdbId) {
    const response = await api.get(
        `/v1/movies/${encodeURIComponent(tmdbId)}`,
    );

    return response.data.data;
}
```

## Acceptance checks for implementation

1. A guest can open a movie from search using its `tmdb_id` and get
   `200` with the documented fields and types.
2. Missing optional fields produce `null` or `[]` as specified.
   A movie without a playable trailer still returns `200`.
3. `movies/search` reaches the search route. Nonnumeric paths return
   `404`, and numeric IDs outside the supported range return `422`.
   Neither invalid request calls TMDB.
4. A confirmed upstream movie `404` produces `MOVIE_NOT_FOUND`.
   A timeout or unusable base payload produces `502`. An upstream
   `429` produces `503` with `Retry-After`.
5. Responses contain no TMDB credentials or raw upstream errors.
   The frontend can render loading, loaded, not-found, and retry states.



## TMDB service implementation — AB#51

`TmdbService::getMovieDetails(int $movieId): array` retrieves and normalizes
the movie details. It makes one authenticated request to
`/movie/{movieId}` with `language=en-US` and
`append_to_response=credits,videos`.

The method returns the movie array described by this contract. The
endpoint controller wraps that array under `data`.

```php
$movie = $tmdbService->getMovieDetails($tmdbId);

return response()->json(['data' => $movie]);
```

The service selects up to eight cast members by billing order, the first
named director, and the first official eligible YouTube trailer. It falls
back to the first eligible unofficial trailer when needed. The supported
YouTube key format is 11 letters, digits, underscores, or hyphens.

Missing optional information becomes `null` or `[]` according to the
response schema. Image URLs use `w342` for posters, `w780` for backdrops,
and `w185` for cast profile images.

### Exceptions for the endpoint implementation

| Exception | Meaning |
| --- | --- |
| `InvalidArgumentException` | The caller supplied an ID outside the supported range. Validate route input before calling the service. |
| `TmdbRequestException` | The request failed. Inspect `upstreamStatus` and `retryAfter`. A connection failure has no upstream status. |
| `UnexpectedValueException` | The response did not contain a usable movie with the requested ID and a non-empty title. |
| `RuntimeException` during service construction | Required TMDB configuration is missing. |

Translate these failures to the HTTP statuses already defined in this
contract. In particular, upstream `404` maps to `404`, upstream `429`
maps to `503`, and connection failures or unusable responses map to `502`.
Apply the contract's retry interval policy in the controller.

Return controlled JSON messages and codes. Never serialize exception
objects or return raw provider responses.

### Verification

From `backend`, run:

```bash
php artisan test --filter=TmdbMovieDetailsTest
php artisan test
```

The dedicated service tests mock TMDB responses and verify authentication,
the single-request behavior, normalization, trailer selection, missing
information, and upstream errors.