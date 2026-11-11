# Local movie catalog architecture — AB#69

## Movie identity

`movies.id` is the generated canonical primary key for every catalog row. Reviews, watchlist entries, custom-list entries, and media assets use `movie_id` foreign keys to `movies.id`. Assign a new local ID when importing a TMDB movie. Resolve an external identity only through the unique `movies.tmdb_id` column. A local ID and a TMDB ID may have the same digits and identify different films. Title and release date are display metadata and play no role in identity resolution.

`source` has two values. A `tmdb` row requires a positive `tmdb_id` in the existing supported range `1` through `2147483647`. A `local` row has `tmdb_id = NULL`. Multiple local rows may have null external IDs. Both `source` and `tmdb_id` are immutable through normal application operations.

## Stored metadata

| Column | Contract |
| --- | --- |
| `title` | Required nonblank display title, up to 255 characters. |
| `overview` | Nullable text containing the description or synopsis. |
| `release_date` | Nullable date. Serialize as `YYYY-MM-DD`. |
| `runtime_minutes` | Nullable positive integer. |
| `genres` | JSON array of `{tmdb_id, name}` objects. A locally entered genre may have a null provider ID. Default to `[]` on creation. |
| `poster_url`, `backdrop_url` | Nullable HTTPS image URLs. |
| `director` | Nullable display name. |
| `cast` | Ordered JSON array of `{tmdb_person_id, name, character, profile_url}` objects. A locally entered person may have a null provider ID. `character` and `profile_url` are nullable. Default to `[]` on creation. |
| `vote_average` | Nullable TMDB average on a 0–10 scale. The provider is TMDB and the value is null for local movies. Store to three decimal places and serialize as a JSON number. User review ratings belong to the review domain. |
| `trailer` | Nullable `{site, key}` object using the existing validated YouTube trailer format. |
| `tmdb_synced_at` | UTC timestamp of the most recent successful import or refresh. Null for local rows. |
| `metadata_overrides` | JSON array of admin-protected field names. Start with `[]`. |
| `deactivated_at` | Nullable UTC timestamp. Null means active. |
| `created_at`, `updated_at` | Standard Laravel timestamps. |

The storage implementation can use the following Laravel schema definitions for identity and lifecycle. Apply the source and range invariant in the persistence layer and enforce it in the database where supported. JSON arrays are initialized by the writer.

```php
$table->id();
$table->unsignedInteger('tmdb_id')->nullable()->unique();
$table->string('source', 8);
$table->json('metadata_overrides');
$table->timestamp('tmdb_synced_at')->nullable();
$table->timestamp('deactivated_at')->nullable();
$table->timestamps();
```

## Import, refresh, and admin edits

Discovery and details GETs never create or refresh catalog rows. An authorized administrator can create a local movie. A signed-in user with a complete profile can explicitly resolve a TMDB ID when starting an action that needs a catalog row. Resolution returns an existing active mapping without a provider call. If there is no mapping, fetch normalized details with `TmdbService::getMovieDetails()`, validate the returned identity, and insert the snapshot. The unique index arbitrates concurrent imports. If another request inserts the same `tmdb_id`, re-read that mapping after the conflict and apply its current active/inactive rule. A provider failure leaves the catalog unchanged.

Refresh is explicit and administrator-triggered for this release. Local details and social features read the stored snapshot. Refresh uses normalized details, including their null and empty-array defaults, to replace unlocked fields. A failed refresh leaves the previous metadata and sync timestamp intact. An upstream 404 does not deactivate the catalog row. Serialize refreshes for the same movie and re-read the current row under a transaction lock before applying updates. Check that the row is still active and apply changes against its latest override list.

On a TMDB row, administrator edits to `title`, `overview`, `release_date`, `runtime_minutes`, `genres`, `poster_url`, `backdrop_url`, `director`, `cast`, or `trailer` add those field names to `metadata_overrides` in the same transaction as the edit. A deliberate null or `[]` is protected. Omitted fields retain their values and protection. `vote_average` remains provider-managed. Clearing an override requires an explicit reset to the provider value. Fetch and validate the snapshot before changing the field and removing its override in one transaction. A failed reset preserves both the value and the override. Local rows have no provider refresh.

## References and lifecycle

Use non-null `foreignId('movie_id')` columns and restrictive delete constraints for movie dependents.

```php
$table->foreignId('movie_id')->constrained('movies')->restrictOnDelete();
```

Normal removal sets `deactivated_at`. Keep the canonical row, its unique external mapping, and all dependent records. Exclude it from local discovery and reject new associations and playback sessions. Historical views may show an unavailable movie label, and users may still remove their own associations. Check that the movie is active in the same transaction that creates a new association. Administrator reactivation clears `deactivated_at` and restores the same canonical ID. A maintenance-only hard delete is possible only when no dependents remain.

## Watch-together media

A movie may have zero or more `movie_media_assets` rows. Each asset has a generated `id`, a required `movie_id`, a private storage `disk` and `object_key`, a `mime_type`, and a nullable `deactivated_at`. Use a unique `(disk, object_key)` pair. The Sprint 4 demo can use an authorized, project-controlled MP4 uploaded or seeded for a catalog movie. TMDB supplies metadata and trailer references. Playback assets are supplied separately.

A playback session pins one active asset by `media_asset_id`. Derive the session’s movie through that asset. Keep the asset’s movie association and stored file identity fixed once referenced. A replacement file gets a new asset ID. Authorize session participants before issuing playback access, and expose a temporary playback URL or authorized stream at playback time. A movie without an active asset has no watch-together playback option.

See the [movie catalog ERD](movie-catalog-erd.md).