# Movie discovery API

`GET /api/v1/movies/discover` and `GET /api/v1/movies/genres` are public, read-only endpoints, like popular/upcoming feeds. They reuse the TMDB client, credentials, timeouts, normalization, and local catalog ID lookup. Discovery never imports movies.

## Query parameters

All discovery parameters are optional and can be combined. Supplied empty values are invalid.

| Parameter | Validation / default | TMDB parameter |
| --- | --- | --- |
| `genre_id` | Positive integer belonging to the current official movie genre list | `with_genres` |
| `year` | Four digits, 1874 through current year + 5 inclusive | `primary_release_year` |
| `min_rating` | Numeric, 0 through 10 inclusive | `vote_average.gte` |
| `sort_by` | One of the options below; default `popularity_desc` | `sort_by` |
| `page` | Integer 1 through 500; default 1 | `page` |

| Sort option | Meaning | TMDB value |
| --- | --- | --- |
| `popularity_desc` | Most popular | `popularity.desc` |
| `popularity_asc` | Least popular | `popularity.asc` |
| `rating_desc` | Highest rated | `vote_average.desc` |
| `rating_asc` | Lowest rated | `vote_average.asc` |
| `release_date_desc` | Newest releases | `primary_release_date.desc` |
| `release_date_asc` | Oldest releases | `primary_release_date.asc` |

Example: `/api/v1/movies/discover?genre_id=28&year=2024&min_rating=7&sort_by=rating_desc&page=1`.

TMDB executes filtering and sorting across its catalog before pagination. Arbitrary upstream parameters are ignored; raw TMDB sorting values are rejected. Genre membership is checked against `/genre/movie/list` when a genre filter is supplied. No independent genre ID list is maintained.

## Responses

Discovery uses the existing feed envelope:

```json
{
  "data": [{
    "tmdb_id": 597,
    "local_movie_id": null,
    "source": "tmdb",
    "title": "Titanic",
    "overview": "A voyage across the Atlantic.",
    "poster_url": "https://image.tmdb.org/t/p/w342/example.jpg",
    "release_date": "1997-12-19",
    "genre_ids": [18, 10749],
    "vote_average": 7.9
  }],
  "meta": {"page": 1, "total_pages": 1, "total_results": 1}
}
```

Local IDs are decimal strings when an active catalog mapping exists, otherwise null. Optional text, poster, date and unrated scores follow existing feed normalization. Genre IDs are a list of positive integers. `total_pages` is capped at TMDB's accessible 500-page limit. Empty results return HTTP 200 with `data: []` and provider totals, including requests beyond the available results.

`GET /api/v1/movies/genres` returns `{"data":[{"id":28,"name":"Action"}]}` using official English TMDB movie genres. Frontend genre selectors should consume this response and use each `id` as the filter value; do not hardcode genre IDs in React. The Discover Movies page provides a Browse by filters mode with this selector, release year, minimum rating, all six sort options, reset, retry and pagination. Title search remains available in its own mode. Filters apply on submission and reset to page 1; pagination uses the last submitted filters.

Invalid discovery parameters return HTTP 422 with Laravel's `message` and field-specific `errors`. Unsupported genre IDs also return 422; genre-provider failures return an upstream error instead of incorrectly rejecting the genre. Provider errors, malformed responses, and connection/timeouts return HTTP 502 with `code: MOVIE_FEED_UNAVAILABLE`. Upstream 429 returns HTTP 503 with `code: MOVIE_FEED_RATE_LIMITED` and a safe `Retry-After` (default 30 seconds). Responses never include credentials or raw provider errors.

## Verification

Run `php artisan test` from `backend`. `MovieDiscoveryTest` uses fake HTTP responses and an in-memory SQLite database for default discovery, individual/combined filters, all sorts, validation, pagination, normalized/local identities, empty results, genres, malformed responses, failures and timeouts. Existing search, details, popular and upcoming tests provide regression coverage.

Upstream reference: [TMDB Discover Movie](https://developer.themoviedb.org/reference/discover-movie).
