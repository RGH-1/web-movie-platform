# AB#150 isolated MySQL stress test

## Purpose and isolation

This validates `GET /api/v1/admin/dashboard` against a populated MySQL or MariaDB database without touching the normal development database. The only permitted target is exactly `web_movie_platform_ab150_test`.

`ab150:prepare` verifies the active database connection and driver before migrations or fixture inserts. It refuses any target other than MySQL/MariaDB on that exact database name and refuses to delete or overwrite application data if `users`, `movies`, `reviews`, or `user_movie_statuses` contain rows. It never invokes `migrate:fresh`, `db:wipe`, truncate, or reset commands.

Normal PHPUnit remains SQLite in-memory. The MySQL suite is opt-in and separate.

## One-time local setup

1. In Navicat (or another MySQL client), create an empty database named `web_movie_platform_ab150_test`. Never use `web_movie_platform`.
2. Copy `backend/.env.ab150.example` to the ignored `backend/.env.ab150`. Fill in local MySQL credentials and an application key copied from the local environment; do not commit it.
3. From `backend`, first verify the live connection:

   ```powershell
   php artisan --env=ab150 ab150:verify-database
   ```

4. Only after it prints `Verified active MySQL database: web_movie_platform_ab150_test`, prepare and test it:

   ```powershell
   php artisan --env=ab150 ab150:prepare
   php artisan --env=ab150 ab150:verify-database
   .\vendor\bin\phpunit --configuration phpunit.ab150-mysql.xml
   ```

`ab150:prepare` uses standard migrations followed by deterministic fixture inserts. It is deliberately one-shot: rerunning it against populated tables aborts without deleting data. The seeded database remains inspectable in Navicat. The MySQL PHPUnit configuration sets `APP_ENV=ab150`; use the direct PHPUnit command above because Laravel's `artisan test` already supplies a PHPUnit configuration option.

## Fixture set

The prepared set contains exactly 121 users (one administrator), 45 local movies, 165 reviews, and 230 user/movie status rows. It has at least 150 reviews, 200 statuses, 30 reliable activity events, multi-genre movies, an empty genre array, duplicate genre entries, a local-name genre, tolerated non-object JSON list values, and two deactivated movies with historical activity. Names, timestamps, and insertion order are fixed.

| KPI | Expected after `ab150:prepare` | Actual in this workspace |
| --- | ---: | --- |
| Total users | 121 | Not executed (isolated MySQL not configured) |
| Total reviews | 165 | Not executed |
| Visible reviews | 133 | Not executed |
| Total movies | 45 | Not executed |
| Watched entries | 89 | Not executed |
| Watchlist entries | 141 | Not executed |

- `AB150 Rating Test`: visible 5 and 3 plus hidden 1. Baseline average is **4.00** from 2 visible reviews; restoring the hidden review changes it to **3.00** from 3, then the test restores the baseline.
- `AB150 Watched Test`: two watched users plus one watchlist-only user; watched count is **2**.
- `AB150 Genre Dedup Test`: Drama (18) and Science Fiction (878), with three distinct user/movie engagements: a watch-and-review user, a reviewer, and a watcher. It contributes **3** to each genre from this movie.
- `AB150 Tie Count Three`, `AB150 Tie Canonical One`, and `AB150 Tie Canonical Two`: prove average-rating, review-count, then movie-ID ordering.
- 39 additional catalog movies ensure both rankings have more than ten candidates. Generic watched counts remain one or two so the named watched fixture stays inspectable.

MySQL JSON columns reject invalid JSON text. The fixtures therefore use valid JSON arrays with unsupported list members (a string and object without `name`) to exercise malformed-entry handling without bypassing the actual schema.

## Formulas

| KPI | Independent calculation |
| --- | --- |
| `total_users` | `COUNT(users)` |
| `total_reviews` | `COUNT(reviews)` |
| `visible_reviews` | visible reviews only |
| `total_movies` | all catalog movies, including deactivated |
| `total_watched_entries` | statuses with non-null `watched_at` |
| `total_watchlist_entries` | statuses where `in_watchlist = true` |

Most watched means distinct users with non-null `watched_at`, ordered watch count descending then movie ID ascending; watchlist-only rows do not count. Highest rated averages visible `reviews.rating`, ordered average descending, review count descending, movie ID ascending. Both include historical deactivated movies and return at most ten rows.

Genre popularity counts unique `(user_id, movie_id)` pairs that either watched a movie or submitted a visible review. A pair that did both counts once; each valid stored genre receives that movie's engagement count. The endpoint groups by positive TMDB genre ID where present, otherwise normalized name, ignores malformed entries, and returns at most ten genres.

Recent activity is a union of review creation and non-null watched timestamps, newest first, then event type ascending and event ID descending. It returns at most 20 events; watchlist-only rows do not produce an event.

## Assertions and performance

`AdminDashboardMySqlStressTest` checks authorization (401 guest, 403 user, 200 admin), all KPIs, named rating/watch/genre cases, ranking limits and ties, deactivated history, the 20-row activity cap and equal-timestamp ordering, privacy, no external HTTP calls, a bounded query count (at most 12), and read-only endpoint behavior. It prints elapsed local response time and query count but imposes no universal timing threshold because hardware and MySQL configuration vary.

It also temporarily replaces the genres of the already-engaged `AB150 Genre Dedup Test` movie with 12 distinct valid genres. The test independently constructs the full ranking from database data, asserts the API returns its first 10 entries in deterministic order, proves the independently calculated eleventh entry is absent, and restores the exact original raw JSON in a `finally` block. No rows are inserted, deleted, reseeded, migrated, or truncated during this case.

Genre aggregation transfers one row per engaged movie before parsing its stored genre metadata. It avoids loading the engagement history, but a very large engaged catalog may eventually justify profiling database-side JSON aggregation or an admin-only cache. The endpoint itself makes no external HTTP requests.

## Executed results

Executed on the existing isolated `web_movie_platform_ab150_test` fixture database:

- `vendor/bin/phpunit --configuration phpunit.ab150-mysql.xml`: **2 tests passed, 88 assertions**.
- Dashboard request measurement: **15.95 ms, 10 queries**. This is the request-only count; independent verification queries are excluded.
- The overflow-genre case restored the selected movie's exact original raw `genres` value and preserved all fixture record counts.

The normal regression suite can be rerun with:

```powershell
cd backend
php artisan test
```

Latest SQLite regression result: **331 passed, 4 skipped, 2553 assertions**.
