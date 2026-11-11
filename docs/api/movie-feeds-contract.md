# Popular and upcoming movie API — AB#73

`GET /api/v1/movies/popular` and `GET /api/v1/movies/upcoming` are public. Send `Accept: application/json`. The optional `page` query parameter accepts integers from `1` through `500` and defaults to `1`. Laravel forwards the page and `language=en-US` to TMDB, using the provider’s default region behavior.

## Response

Both routes return `data` and `meta` in the same shape as movie search. Example for `GET /api/v1/movies/popular?page=2`:

```json
{
  "data": [
    {
      "tmdb_id": 597,
      "local_movie_id": "42",
      "title": "Titanic",
      "overview": "A voyage across the Atlantic.",
      "release_date": "1997-12-19",
      "poster_url": "https://image.tmdb.org/t/p/w342/example.jpg",
      "vote_average": 7.9
    }
  ],
  "meta": {
    "page": 2,
    "total_pages": 5,
    "total_results": 81
  }
}
```

Card metadata and ordering come from TMDB. Missing or unusable optional fields are `null`. `vote_average` uses TMDB’s 0–10 scale and is null when no usable rating exists.

`tmdb_id` is an integer and is used in the existing TMDB detail URL. `local_movie_id` is the canonical `movies.id` as a decimal string when an active catalog row matches `movies.tmdb_id`. It is `null` for unmatched or deactivated movies. Feed requests perform no catalog writes. See the [catalog identity contract](movie-catalog-contract.md).

`meta.page` echoes the requested page, `meta.total_pages` is capped at `500`, and `meta.total_results` is the provider’s total. A successful empty page returns `200` with `data: []` and the pagination metadata.

## Errors

| Status | Meaning |
| --- | --- |
| `422` | Invalid `page`. The Laravel validation response includes `errors.page`. |
| `502` | TMDB request failed or returned unusable data. Code `MOVIE_FEED_UNAVAILABLE`. |
| `503` | TMDB returned `429`. Code `MOVIE_FEED_RATE_LIMITED`. `Retry-After` contains positive integer seconds, with a 30-second fallback. |

The existing search and TMDB details endpoints still require authentication and a complete profile.