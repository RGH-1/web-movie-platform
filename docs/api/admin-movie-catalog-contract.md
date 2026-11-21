# Administrator movie catalog API — AB#154

All endpoints require Sanctum authentication and the existing `access-admin` gate. Guests receive `401`; authenticated non-administrators receive `403`. Responses use `Cache-Control: private, no-store` and never call TMDB or Azure.

## Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/admin/movies` | Search/list all stored movies, including deactivated rows. |
| GET | `/api/v1/admin/movies/{movieId}` | Read a complete editable catalog row. |
| POST | `/api/v1/admin/movies` | Create a local-only movie. |
| PATCH | `/api/v1/admin/movies/{movieId}` | Update approved metadata. |
| DELETE | `/api/v1/admin/movies/{movieId}` | Logically deactivate a movie. |

`local_movie_id` is the canonical `movies.id`, serialized as a string. It is never interchangeable with `tmdb_id`.

## List and details

`GET /api/v1/admin/movies` accepts `search` (title substring), `source` (`local` or `tmdb`), `status` (`active`, `deactivated`, or `all`, the default), `page`, and `per_page` (1–50; default 20). It sorts by title then canonical ID. Invalid parameters return `422` field errors.

```json
{
  "data": [{
    "local_movie_id": "42", "title": "Example", "source": "local", "tmdb_id": null,
    "poster_url": null, "release_date": "2026-05-15", "is_active": true, "deactivated_at": null
  }],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 },
  "links": { "next": null, "previous": null }
}
```

Details include the list fields plus `overview`, `runtime_minutes`, `genres`, `backdrop_url`, `director`, `cast`, `trailer`, `vote_average`, `tmdb_synced_at`, and `metadata_overrides`. Admins can inspect inactive rows.

## Create local movie

```http
POST /api/v1/admin/movies
Content-Type: application/json

{
  "title": "A Lebanese Short Film", "overview": "An independent short film.",
  "release_date": "2026-05-15", "runtime_minutes": 28,
  "genres": [{"tmdb_id": 18, "name": "Drama"}], "cast": [],
  "poster_url": null, "backdrop_url": null, "director": "Example Director", "trailer": null
}
```

The server assigns `source: "local"`, null `tmdb_id`, `vote_average`, and `tmdb_synced_at`, and `metadata_overrides: []`. It returns `201` with the detail representation.

## Update and provider identities

`PATCH /api/v1/admin/movies/{movieId}` accepts a partial body of: `title`, `overview`, `release_date`, `runtime_minutes`, `genres`, `poster_url`, `backdrop_url`, `director`, `cast`, and `trailer`. Explicit `null` and empty arrays are supported for nullable/array fields.

For TMDB rows, every supplied approved field is appended once to `metadata_overrides`, including explicit null or empty-array overrides. Omitted fields neither change nor become overrides. `source`, `tmdb_id`, canonical IDs, `vote_average`, `tmdb_synced_at`, `metadata_overrides`, and `deactivated_at` are rejected with `422`; identities and provider ratings remain immutable. No refresh is initiated.

Validation trims title/overview/director, requires a nonblank title, uses strict valid `YYYY-MM-DD` dates, positive runtime/provider IDs, HTTPS URLs, valid genre/cast object shapes, and `{ "site": "YouTube", "key": "11-character YouTube key" }` trailers. Unknown and protected fields return field-level errors.

## Deactivation and relationships

`DELETE /api/v1/admin/movies/{movieId}` sets `deactivated_at` once and returns the full inactive representation. Repeating it returns `200` with the original timestamp. It does not delete movies, reviews, statuses, watched history, movie lists, or list membership. There is intentionally no reactivation endpoint in this contract.

## User-facing stored catalog

`GET /api/v1/catalog/movies` is available to authenticated users with complete profiles. It lists active local and TMDB catalog rows with optional `search`, `source`, `page`, and `per_page` (maximum 50), ordered by title then ID. It excludes deactivated rows. `GET /api/v1/catalog/movies/{movieId}` continues to return active stored details only; it returns the established `404 MOVIE_NOT_FOUND` for deactivated rows.

Run `php artisan test --filter=AdminMovieCatalogTest` and `php artisan test` from `backend`.
