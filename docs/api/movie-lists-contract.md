# Custom movie lists API — AB#91

## Access and identity

All endpoints require `auth:sanctum`: public lists are shared with signed-in users,
not anonymous visitors. Only writes additionally require `profile.complete`.
Guests receive 401. Incomplete profiles receive 403 on writes.
Private lists are visible only to their owner. Missing, malformed, private-to-other,
and non-owned write targets return 404 so private list existence is not disclosed.
Responses containing list data use `Cache-Control: private, no-store`.

Send `Accept: application/json`. Browser writes use the existing Sanctum cookie/CSRF
flow. List IDs and canonical movie IDs are positive decimal strings without leading
zeroes, up to 9223372036854775807. Never send a TMDB ID as `movie_id`.
Use the existing explicit TMDB resolve endpoint first if a movie has no catalog ID.

| Method and path (under /api/v1) | Behavior |
| --- | --- |
| GET /me/lists | Paginated summaries of the signed-in user's lists, including private lists. |
| GET /lists/public | Paginated public-list discovery for signed-in users. Used by [Public movie lists](../frontend/public-movie-lists.md) (AB#109). |
| GET /lists/{listId} | Summary plus paginated movie summaries; public or owner-only private access. |
| POST /lists | Create an owned list, 201. |
| PATCH /lists/{listId} | Update owned list metadata, 200. |
| DELETE /lists/{listId} | Delete owned list and entries, 204. Movies survive. |
| POST /lists/{listId}/movies | Add an active canonical movie, 200; repeat adds succeed without duplicates. |
| DELETE /lists/{listId}/movies/{movieId} | Remove membership, 204; absent membership also succeeds. |

The literal `/lists/public` route precedes `/lists/{listId}`.

## Requests and validation

Create accepts `name` (required nonblank string, max 255), `description`
(optional nullable string, max 5000), and `is_public` (optional Laravel boolean,
default false). PATCH accepts the same fields, all optional; omitted fields are
preserved, explicit null clears the description. Name and visibility cannot be null.
Ownership comes from the authenticated user; supplied `user_id` and `id` are rejected.
The 5000-character description bound limits user-supplied text at the API boundary.
Other unknown metadata fields are ignored and never persisted.

Membership POST accepts `{ "movie_id": "42" }`; numeric JSON IDs are rejected.
Missing/noncanonical body values return Laravel 422 validation errors. Syntactically
canonical but out-of-range or nonexistent IDs return 404. Inactive movies also
return 404 for adds. Existing inactive memberships remain readable and removable.
Membership writes lock the owned list in a transaction; adds also lock the active
movie. The composite database key remains the final duplicate safeguard.

All collection GETs accept `page` (integer >=1) and `per_page` (1–50, default 12).
Lists sort by creation time then ID descending. Memberships sort by insertion time
then canonical movie ID descending. Invalid pagination returns Laravel 422.

## Responses

Create, PATCH, and membership POST return `{ "data": <list summary> }`.
`GET /me/lists` and `/lists/public` return `{ "data": [<list summary>], "meta": ... }`.
A summary contains:

```json
{
  "id": "7",
  "name": "Weekend",
  "description": null,
  "is_public": false,
  "author": { "id": "3", "name": "Lynn" },
  "movie_count": 1,
  "created_at": "2026-11-14T12:00:00+00:00",
  "updated_at": "2026-11-14T12:00:00+00:00"
}
```

Author email and other profile/private fields are never included. Detail GET adds
`data.movies`, an array containing `local_movie_id` (string), `tmdb_id` (integer or
null), `source`, `title`, `overview`, `poster_url`, `release_date` (YYYY-MM-DD or null),
`vote_average` (TMDB 0–10 or null), and `is_active`. No TMDB requests occur.
`movie_count` includes all memberships, including inactive movies.

`meta` is `{ "current_page": 1, "last_page": 1, "per_page": 12, "total": 1 }`.
On detail GET it describes the movie page; `data.movie_count` is the whole-list count.
The list's `updated_at` describes metadata edits, not membership changes.

## Verification and manual checks

From backend: `php artisan test --filter=MovieListApiTest`, then `php artisan test`.
Automated checks cover auth, CRUD, ownership, privacy, pagination, validation,
canonical-ID handling, inactive movies, duplicate adds, and deletion behavior.

Manual testing uses the existing login and Axios client; there is no list UI until AB#92.

1. Start Laravel and Vite with the project's configured database. Run pending
   migrations using `php artisan migrate` (never `migrate:fresh` on working data).
2. Log in with a complete profile. Open browser developer tools on the Vite app.
   Import its shared client once in Console:
   `const { default: listApi } = await import('/src/services/api.js');`
3. Create a list:
   `const createdList = (await listApi.post('/v1/lists', { name: 'API test' })).data.data;`
   Check `createdList.is_public` is false and note its string ID.
4. Call `await listApi.get('/v1/me/lists')` and
   `await listApi.get('/v1/lists/' + createdList.id)`; confirm the list is present and empty.
5. Use a real canonical movie ID from the database or the existing resolve endpoint.
   POST `{ movie_id: 'YOUR_CANONICAL_ID' }` to `/v1/lists/ID/movies` twice.
   Detail GET should show one movie with the correct provider ID and movie_count 1.
6. PATCH `/v1/lists/ID` with `{ is_public: true }`. Confirm it appears in
   `/v1/lists/public`. In a separate signed-in account/browser session, confirm it
   is readable but PATCH/DELETE/membership writes return 404. Switch it back to
   private as the owner; confirm the other account now receives 404 on detail.
7. Send a blank name and invalid pagination. Confirm 422 responses and unchanged data.
   Use a logged-out session to confirm list reads return 401.
8. As the owner, remove membership and delete the test list. Confirm the underlying
   catalog movie is still present. Clean up only the test list you created.

The duplicate LocalMovieDetailsController import in routes/api.php was also removed:
it was a pre-existing merged syntax error that prevented all API routes from loading.
