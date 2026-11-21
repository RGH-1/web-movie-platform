# Movie API and canonical catalog contract — AB#69, AB#71, AB#102

`tmdb_id` is TMDB’s integer provider ID. `local_movie_id` is the canonical
`movies.id`, serialized as a decimal string. Preserve the string in React
and use it for reviews, watchlists, and list membership.

| Method and path | Access | Behavior |
| --- | --- | --- |
| `GET /api/v1/movies/search?query=...` | Authenticated, complete profile | Searches TMDB and annotates cards with existing active catalog IDs. |
| `GET /api/v1/movies/popular` | Public | Returns popular TMDB cards with existing active catalog IDs. |
| `GET /api/v1/movies/upcoming` | Public | Returns upcoming TMDB cards with existing active catalog IDs. |
| `GET /api/v1/movies/{tmdbId}` | Authenticated, complete profile | Returns live TMDB details with stored administrator overrides. Performs no catalog writes. |
| `GET /api/v1/catalog/movies/{movieId}` | Authenticated, complete profile | Reads the active catalog record by canonical ID without calling TMDB. |
| `GET /api/v1/catalog/movies` | Authenticated, complete profile | Lists active stored local and TMDB catalog rows without calling TMDB. |
| `POST /api/v1/movies/tmdb/{tmdbId}/resolve` | Authenticated, complete profile | Reuses an active catalog mapping or imports the movie if no mapping exists. |

Send `Accept: application/json`. Protected routes use `auth:sanctum` and
`profile.complete`. Browser clients use the Sanctum CSRF flow for POST.

## Search, feeds, and details

Search and feed cards include `source: "tmdb"` and `local_movie_id`, which
is `null` unless an active mapping exists. Search and feeds perform no
catalog writes. The temporary TMDB diagnostic route is unchanged.

TMDB details retain the existing normalized fields and include
`source: "tmdb"`, `local_movie_id`, `catalog_deactivated`, and the
authenticated viewer’s `viewer_review`. An active mapping returns its
canonical ID. An unmapped movie returns `local_movie_id: null` and
`catalog_deactivated: false`. A deactivated mapping returns
`local_movie_id: null` and `catalog_deactivated: true`. Its live details
remain available, and new reviews are blocked.

Local details return the stored metadata for either a local-only or an
imported movie, including `local_movie_id`, `source`, nullable `tmdb_id`,
and `viewer_review`. Both details routes return their payload under `data`
and use `Cache-Control: private, no-store` if successful. Missing or deactivated local
movies return `404 MOVIE_NOT_FOUND`. Invalid detail IDs return
`422 INVALID_MOVIE_ID`.

The catalog listing accepts bounded `page`/`per_page` (maximum 50), optional
`search`, and optional `source=local|tmdb`. It is title/ID ordered, includes
canonical IDs and safe card metadata, and excludes deactivated rows.

## Resolve or import a TMDB movie

The resolver takes the TMDB ID in the path and needs no request body. It
returns `200` with `data.local_movie_id`, `data.tmdb_id`, and
`data.source`. It reuses an existing active mapping without calling TMDB.
If no mapping exists, it imports normalized TMDB details. Repeated
successful requests return the same canonical ID.

```json
{
  "data": {
    "local_movie_id": "42",
    "tmdb_id": 157336,
    "source": "tmdb"
  }
}
```

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `MOVIE_NOT_FOUND` | The mapping is deactivated or TMDB could not find the movie. |
| `422` | `INVALID_MOVIE_ID` | The TMDB ID is invalid. |
| `502` | `MOVIE_RESOLVE_UNAVAILABLE` | The provider request failed or returned unusable details. |
| `503` | `MOVIE_RESOLVE_RATE_LIMITED` | TMDB rate-limited the request. `Retry-After` is in seconds. |

When the review form already has a `local_movie_id`, it submits directly
to the reviews API. For an unmapped TMDB movie, it calls the resolver
after form validation and uses the returned canonical ID in the review
creation URL. See the [reviews API](movie-reviews-contract.md).

Run the backend regression suite with `php artisan test` from `backend`.
See also [Movie Details](movie-details-contract.md),
[movie search](movie-search-contract.md), and
[catalog storage](../architecture/movie-catalog-storage.md).
