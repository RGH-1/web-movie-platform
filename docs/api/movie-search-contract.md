# Movie search API contract

Work item: AB#40 — Define the movie search endpoint and response format  
Status: Proposed for team review

## Endpoint

`GET /api/v1/movies/search`

Movie search is public. It accepts query parameters and returns JSON.
Send `Accept: application/json`. React calls Laravel, and Laravel calls
TMDB's `/search/movie` through `TmdbService`. The TMDB credential stays
on the backend.

The existing `/api/integration/tmdb/search` route serves the integration
test page. The discovery frontend uses the versioned endpoint documented
here.

## Query parameters

| Parameter | Required | Default | Validation |
| --- | --- | --- | --- |
| `query` | Yes | — | Trim surrounding whitespace. Require a string of 1–200 characters after trimming. Reject missing, empty, whitespace-only, or array values. Allow punctuation, accents, and non-Latin titles. |
| `page` | No | `1` | Require an integer from `1` to `500` when supplied. Reject blank, fractional, non-numeric, and out-of-range values. |

Only forward the validated `query` and `page` as client-supplied
parameters. Laravel sets `language=en-US` and `include_adult=false`
for the initial release. When the user changes the query, React starts
again at page `1`.

Examples:

```http
GET /api/v1/movies/search?query=Interstellar
GET /api/v1/movies/search?query=The%20Matrix&page=2
```

Laravel Form Request reference. Normalize a string `query` before
validation. Read these values from the query string, then default
`page` to `1` after validation when it is absent.

```php
return [
    'query' => ['bail', 'required', 'string', 'max:200'],
    'page' => ['sometimes', 'bail', 'required', 'integer', 'between:1,500'],
];
```

## Successful response

Return `200 OK` with a `data` array and a `meta` object. Preserve
TMDB's search result order. The example below uses illustrative data.

```json
{
  "data": [
    {
      "tmdb_id": 157336,
      "title": "Interstellar",
      "overview": "A team travels beyond Earth in search of a new home.",
      "release_date": "2014-11-05",
      "poster_url": "https://image.tmdb.org/t/p/w342/example-poster.jpg",
      "vote_average": 8.4
    }
  ],
  "meta": {
    "page": 1,
    "total_pages": 1,
    "total_results": 1
  }
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `tmdb_id` | Integer | The TMDB movie ID. React uses it as the result key and passes it to the future movie details route. |
| `title` | String | Display title returned by TMDB. |
| `overview` | String or `null` | Short description. Use `null` when missing or blank. |
| `release_date` | String or `null` | Release date in `YYYY-MM-DD` format. Use `null` when unavailable. |
| `poster_url` | String or `null` | Full HTTPS poster URL constructed by Laravel from the TMDB image base, size, and poster path. Use `null` when no poster is available. |
| `vote_average` | Number or `null` | TMDB's average rating on a 0–10 scale. Use `null` when the movie has no votes or no rating is available. |
| `meta.page` | Integer | The requested page, including when it has no results. |
| `meta.total_pages` | Integer | The number of accessible pages, capped at `500`. Use `0` when no movies match. |
| `meta.total_results` | Integer | The total matching movie count reported by TMDB. It may include matches beyond the accessible page limit. |

React can use `page > 1` for Previous and `page < total_pages` for Next.
When a valid requested page is past the last result page, return an
empty `data` array with the requested page and the search totals. The
frontend can offer to return to page `1`.

## No matching movies

Return `200 OK`. A search that finds nothing is a successful request
with an empty list.

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "total_pages": 0,
    "total_results": 0
  }
}
```

React shows a no-results message when `total_results` is `0`. If
`total_results` is positive and `data` is empty, show that the requested
page has no results.


## Error responses

| HTTP status | Condition | Frontend behavior |
| --- | --- | --- |
| `422` | Missing, empty, or invalid `query`, or invalid `page`. Laravel rejects the request before calling TMDB. | Show the relevant field message from `errors.query` or `errors.page`. |
| `502` | TMDB request fails because of a connection error, timeout, unexpected upstream status other than `429`, or an unusable response. | Show that movie search is temporarily unavailable and offer a manual retry. |
| `503` | TMDB returns `429` because the application's shared upstream access has been throttled. | Show a temporary availability message. Wait for the `Retry-After` interval before offering a retry. |

Use Laravel's standard JSON validation format for `422`. For example,
`GET /api/v1/movies/search?page=1` returns:

```json
{
  "message": "The query field is required.",
  "errors": {
    "query": ["The query field is required."]
  }
}
```

Example `502` response:

```json
{
  "message": "Movie search is temporarily unavailable.",
  "code": "MOVIE_SEARCH_UNAVAILABLE"
}
```

Example response when TMDB returns `429`:

```http
HTTP/1.1 503 Service Unavailable
Content-Type: application/json
Retry-After: 30
```

```json
{
  "message": "Movie search is busy. Please try again shortly.",
  "code": "MOVIE_SEARCH_RATE_LIMITED"
}
```

For an upstream `429`, use TMDB's `Retry-After` when it can be converted
to a positive delay in seconds. Otherwise use `30` seconds. Send the
delay as the Laravel response's `Retry-After` header. Avoid immediate
automatic retries. Keep provider response bodies, credentials, and
internal exception details out of the client response. Handle missing
server configuration as an internal `500` error.

## Implementation handoff

- Register `/movies/search` inside the existing `v1` route group, outside
  the `auth:sanctum` group. When the movie details route is added, constrain
  its ID parameter to digits so it cannot capture `/movies/search`.
- Validate and normalize the request before calling TMDB. Map successful
  TMDB results to the documented `data` and `meta` fields.
- The current `TmdbService` throws a generic exception for failed HTTP
  responses. Preserve the upstream status and `Retry-After` in the
  implementation so the search endpoint can distinguish `429` from
  other failures.
- Use the existing Axios client. Its base URL already ends in `/api`,
  so request `/v1/movies/search` relative to that base.

```js
import api from './api';

export async function searchMovies(query, page = 1) {
    const response = await api.get('/v1/movies/search', {
        params: { query, page },
    });

    return response.data;
}
```

Axios encodes the parameters. React should handle loading, results, an
empty result, validation errors, and temporary failures as distinct states.

## Acceptance checks for implementation

1. A guest can search without a session. Omitting `page` requests page
   `1`, and supplying `page=2` requests the second page.
2. Missing, blank, array, or overlong queries return `422` without a
   TMDB call. Invalid pages such as `0`, `1.5`, and `501` also return `422`.
3. A successful response contains the documented movie fields and
   pagination metadata. Missing posters, dates, and ratings use `null`.
4. No matches returns `200` with `data: []` and zero totals. A valid page
   beyond the result set returns an empty page with the existing totals.
5. A mocked TMDB `429` returns `503` with `Retry-After`. Other provider
   failures return the documented `502` response.