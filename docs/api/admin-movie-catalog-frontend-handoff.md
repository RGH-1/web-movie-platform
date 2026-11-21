# AB#155 frontend handoff — administrator movie management

Use authenticated Sanctum requests to `/api/v1/admin/movies`. The existing admin gate returns `401` for no session and `403` for a normal user; display these as access errors rather than retrying.

| UI action | Request |
| --- | --- |
| Table | `GET /admin/movies?search=&source=local|tmdb&status=active|deactivated|all&page=1&per_page=20` |
| Edit form load | `GET /admin/movies/{local_movie_id}` |
| Create | `POST /admin/movies` with local metadata |
| Save | `PATCH /admin/movies/{local_movie_id}` with changed editable fields only |
| Deactivate confirmation | `DELETE /admin/movies/{local_movie_id}` |

The list `meta` has `current_page`, `last_page`, `per_page`, and `total`; use `links.next`/`links.previous` when available. Each row has `local_movie_id` (a string for React route/navigation), `source`, nullable `tmdb_id`, `poster_url`, and `is_active`. Render a local/TMDB badge from `source`, render a missing-poster fallback when `poster_url` is null, and keep deactivated rows visible only in the admin table.

The edit response supplies all form fields and `metadata_overrides`. Editable fields are title, overview, release date, runtime, genres, poster/backdrop HTTPS URLs, director, cast, and YouTube trailer. Do not submit `source`, IDs, provider rating, sync time, overrides, or lifecycle fields; a `422` response has field-level `errors` suitable for controls. For TMDB rows, changed approved fields become protected overrides automatically.

Create returns `201`, update/deactivate return `200`, and all successes wrap the movie in `data`. Deactivation is idempotent and does not remove history. Navigate to a stored detail route using `local_movie_id`; ordinary users can browse active records through `GET /api/v1/catalog/movies` and open `/api/v1/catalog/movies/{local_movie_id}`. A deactivated movie is unavailable there, by design.
