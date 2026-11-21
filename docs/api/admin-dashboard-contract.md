# Administrator dashboard API — AB#150

## Endpoint and access

`GET /api/v1/admin/dashboard` returns stored platform statistics for an
authenticated administrator. It uses the existing Sanctum session and
`can:access-admin` Gate: guests receive `401`, signed-in non-administrators
receive `403`, and administrators receive `200`.

The response is read-only and includes `Cache-Control: private, no-store`.
No email addresses, passwords, tokens, review text, or other private account
data is returned.

## Response

```json
{
  "data": {
    "kpis": {
      "total_users": 0,
      "total_reviews": 0,
      "visible_reviews": 0,
      "total_movies": 0,
      "total_watched_entries": 0,
      "total_watchlist_entries": 0
    },
    "most_watched_movies": [],
    "highest_rated_movies": [],
    "popular_genres": [],
    "recent_activity": []
  }
}
```

Movie and user IDs are canonical decimal strings. Counts and averages are JSON
numbers. An empty catalog returns empty ranking/activity arrays and zero counts
except for the authenticated administrator's own user row.

## Formulas and limits

- **KPIs:** direct database counts. `visible_reviews` includes only reviews
  with `moderation_status = visible`; watched entries require non-null
  `watched_at`; watchlist entries require `in_watchlist = true`.
- **Most watched:** at most 10 movies, using distinct users with non-null
  `watched_at`. Watchlist-only rows do not count. Historical watched rows for
  deactivated movies remain included. Ordering is watch count descending, then
  movie ID ascending.
- **Highest rated:** at most 10 movies using visible platform reviews only.
  It returns `average_rating` rounded to two decimals and `review_count`.
  Ordering is average descending, review count descending, then movie ID.
- **Popular genres:** at most 10 genres. A user/movie engagement is the union
  of a watched row and a visible review for that same user/movie, so the pair
  counts once even when both exist. Its engagement count contributes to every
  valid genre object in that movie's stored JSON array. Genres group by TMDB
  genre ID when present, otherwise by normalized name. Malformed genre objects
  are ignored. Ordering is engagement count descending, then genre name and ID.
- **Recent activity:** at most 20 events from review creation and movies marked
  watched. Ordering is timestamp descending, then event type and event ID for
  deterministic ties. Watchlist-only changes are not represented because the
  schema has no reliable watchlist audit history.

The implementation aggregates user/movie engagement in SQL before expanding
the corresponding movie JSON metadata, avoiding an in-memory scan of the
engagement history and keeping SQLite tests portable.

## Testing

```bash
cd backend
php artisan test --filter=AdminDashboardTest
php artisan test
```
