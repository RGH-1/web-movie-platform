# Review storage — AB#61

A review stores one user’s required whole-number rating from 1 through 5 and optional `review_text`. Use `null` for a rating-only submission. The database allows one row per `(user_id, movie_id)` pair. `movie_id` references the canonical `movies.id` from the [catalog architecture](movie-catalog.md). The platform rating is separate from TMDB’s `vote_average`.

Reviews default to `visible`. Moderation can set `moderation_status` to `hidden` and record the acting user in `moderated_by`, the time in `moderated_at`, and an optional `moderation_reason`. All three moderation detail fields start as `null`.

`User::reviews()` and `Movie::reviews()` expose the authored reviews and the reviews for a movie, respectively. `Review::user()`, `Review::movie()`, and `Review::moderator()` resolve those references. Deleting the author cascades their reviews. Deleting the moderator clears `moderated_by` and preserves the moderation status, timestamp, and reason. Movie deactivation preserves reviews. The database restricts hard deletion of a movie while a review references it.

## Contract for the review API

Only `rating` and `review_text` are mass assignable. Derive the author from the authenticated request and associate the movie from the resolved canonical catalog row. Check that the movie is active in the same transaction that creates the review. A future moderation action must authorize the administrator before setting moderation fields. Public review queries should filter to `visible`.

Run `php artisan test --filter 'ReviewTest|MovieCatalogTest'` from `backend`. The default SQLite suite covers model rating validation, database uniqueness, and foreign-key behavior. The direct SQL rating range check runs on MySQL or MariaDB.