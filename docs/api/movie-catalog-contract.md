# Movie catalog API contract — AB#69

All routes below require `auth:sanctum` and `profile.complete`. Send `Accept: application/json`. Guests receive `401`, and signed-in users with incomplete profiles receive `403 PROFILE_INCOMPLETE`.

| Method and path | Identity and behavior |
| --- | --- |
| `GET /api/v1/movies/search` | Existing live TMDB search. Keep the search result `tmdb_id`, result fields, and pagination as shipped. |
| `GET /api/v1/movies/{tmdbId}` | Existing live TMDB details. Returns `data.local_movie_id` as a decimal string when an active catalog row matches `movies.tmdb_id`, or `null` when there is no active mapping. |
| `GET /api/v1/movies/local/{movieId}` | Read one active catalog movie by `movies.id`, regardless of source. Serve the stored snapshot without a TMDB call. |
| `POST /api/v1/movies/tmdb/{tmdbId}/resolve` | Resolve or import the TMDB movie. No body is required. Return `200` and the same identity fields for the first successful import and all subsequent successful resolutions. Apply the existing Sanctum SPA CSRF flow. |

`tmdb_id` is a JSON integer in provider responses and may be null on a local catalog detail. `local_movie_id` is a decimal string or null in a successful legacy detail response. It is always a string in successful local detail and resolve responses. Local route parameters and social request `movie_id` values use the same decimal string format. Accept canonical positive digits without leading zeroes up to `9223372036854775807`. Preserve the string end to end in React to avoid precision loss. Local details use the existing metadata field names and add `source`. Provider genre and cast IDs may be null on local details, so the local UI needs rendering keys that work for manually entered metadata.

Example resolve response for `POST /api/v1/movies/tmdb/157336/resolve`:

```json
{
  "data": {
    "local_movie_id": "42",
    "tmdb_id": 157336,
    "source": "tmdb"
  }
}
```

To see why the route matters, suppose catalog row `42` has `tmdb_id = 157336`, while TMDB also has a different movie whose provider ID is `42`. `/api/v1/movies/local/42` reads the catalog row. `/api/v1/movies/42` asks TMDB for provider movie `42` and finds its mapping only through `movies.tmdb_id = 42`. A review, watchlist entry, or list entry with `"movie_id": "42"` attaches to catalog row `42`.

Keep the existing React route `/movies/:tmdbId` and discovery links. Use `/movies/local/:movieId` for the future catalog details screen. For an action from the TMDB screen, use `local_movie_id` if present. When it is null, call the explicit resolve endpoint and use its returned `local_movie_id` as `movie_id` in the feature request. On the server, validate the local ID and check that the row is active when creating the association.

Malformed detail and resolve identifiers return `422 INVALID_MOVIE_ID`. Missing or inactive rows return `404 MOVIE_NOT_FOUND` from local details and for an inactive TMDB mapping at resolve time. Inactive catalog mappings can still appear in live TMDB search and details, where `local_movie_id` is null. They cannot be imported again or used to create associations. For resolve calls that need TMDB, map upstream not-found to `404`, upstream throttling to `503` with `Retry-After`, and connection or unusable-response failures to `502`, following the details API policy. Local details remain available during TMDB outages.

Place both new routes in the existing `v1`, `auth:sanctum`, and `profile.complete` groups when implemented. Validate the identifier from the route parameter. A Form Request for resolution should read `$this->route('tmdbId')`. Register the literal search route before the legacy one-segment detail route to preserve routing and the existing `422` behavior.

See the [catalog architecture](../architecture/movie-catalog.md) for import, refresh, overrides, and deactivation.