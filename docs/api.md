# Movie API and canonical catalog contract

Movie identities are separate: `tmdb_id` is the integer provider ID;
`local_movie_id` is the canonical `movies.id`, serialized as a decimal string.
Use the canonical ID for reviews and future watchlists/list membership.

| Route | Identity | Persistence |
| --- | --- | --- |
| `GET /api/v1/movies/search?query=...` | TMDB search | Read-only |
| `GET /api/v1/movies/popular` and `/movies/upcoming` | TMDB feeds | Read-only |
| `GET /api/v1/movies/{tmdbId}` | TMDB ID | Resolves/imports detailed metadata |
| `GET /api/v1/catalog/movies/{movieId}` | Canonical local ID | Read-only; no external calls |

Search and details retain their Sprint 2 fields, envelopes, pagination, validation,
and external-error responses. Search/feed cards additionally contain `source: "tmdb"`
and `local_movie_id` (null until an active mapping exists). Search never imports
results. The temporary `/api/integration/tmdb/search` diagnostic route is unchanged.

TMDB details return `data.local_movie_id` and `data.source: "tmdb"`, together with
all existing normalized detail fields and the authenticated viewer's `viewer_review`.
Repeated successful requests return the same canonical ID. The unique `tmdb_id`
index and transaction protect duplicate resolution, including competing inserts.
Invalid/missing/mismatched provider movies and external failures create no records.
Existing 404, 422, 502 and rate-limit 503/Retry-After behavior remains unchanged.
A deactivated mapping stays deactivated: provider details remain available with a
null canonical ID, preserving the existing Sprint 2 behavior. It is never duplicated.

The local details route supports both local-only and imported records. It returns
`{ "data": { ... } }` with `local_movie_id`, `source` (`local` or `tmdb`), nullable
`tmdb_id`, title, overview, release_date, runtime_minutes, genres, poster_url,
backdrop_url, director, cast, vote_average, trailer, and viewer_review. Dates use
`YYYY-MM-DD`; optional metadata is null or an empty list. Local-only movies have
no provider rating. Internal override/sync/lifecycle attributes are not exposed.
Missing/deactivated local movies return 404 `MOVIE_NOT_FOUND`; invalid identifiers
return 422 `INVALID_MOVIE_ID`. Both details routes require authentication and a
complete profile; personalized responses use `Cache-Control: private, no-store`.
Public feed access and existing review-route permissions remain unchanged.

`MovieCatalogService::resolveTmdbId(int)` is reusable by user-interaction services:
it returns an existing record without calling TMDB, or fetches normalized details
and creates the canonical record. Callers must respect `deactivated_at` before
creating interactions. Provider exceptions propagate to the caller's error handler.
`resolveDetails(array)` accepts the normalized `TmdbService::getMovieDetails`
contract, not raw provider JSON. TmdbService normalizes dates, positive runtimes,
image URLs, genres, principal cast, director, rating and YouTube trailer metadata.
Only explicitly listed metadata fields are imported. Refreshes respect the
`metadata_overrides` list of administrator-managed field names and never change
source, provider identity, override settings, or deactivation. Unprotected metadata
and `tmdb_synced_at` are refreshed on successful detail requests.

Run regression checks from `backend` with `php artisan test`, or
`php vendor/phpunit/phpunit/phpunit`. Tests use SQLite in memory and fake TMDB;
no real API credentials or development-database migrations are required.
See also [Sprint 2 details](api/movie-details-contract.md),
[search](api/movie-search-contract.md), and [catalog schema](api/movie-catalog-contract.md).
