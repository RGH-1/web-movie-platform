# Movie catalog storage (AB#70)

## Implemented scope

This task implements only the movies table, Movie model, factory, and
tests based on the AB#69 architecture and ERD. It does not create review,
watchlist, custom-list, list membership, media, or playback tables/models.
It does not change routes or implement TMDB resolution/import (AB#71).

movies.id is an independently generated canonical bigint key. The model
serializes it as a decimal string to avoid precision loss in React.
movies.tmdb_id is a nullable unique provider ID; never substitute it for
the canonical key.

The ERD names are authoritative for future relationships:
- Movie::reviews() targets Review, reviews.movie_id.
- Movie::watchlistEntries() targets WatchlistEntry, watchlist_entries.movie_id.
- Movie::customLists() targets CustomList through list_movies, using
  movie_id and list_id.

Review, WatchlistEntry, and CustomList are delivered by their own G/H/I
tasks. Do not call these relationships until those models/tables exist.
Relationship-key tests use related-model test doubles rather than adding
another team's production models.

Dependent movie_id columns must be non-null foreignId columns referencing
movies.id with restrictOnDelete(), as specified by P1. List membership
uses the ERD table names custom_lists/list_movies, not the alternative
names in the initial Azure descriptions.

## Storage and invariants

- source must be local or tmdb. Local rows have no TMDB ID, provider
  rating, or TMDB sync timestamp. TMDB IDs range from 1 to 2147483647.
- Source and provider identity cannot change through an existing model's
  save/update. Imports must create a new local key and resolve provider
  identity through the unique tmdb_id column.
- Title is trimmed and required. Runtime must be positive when present.
  The optional provider rating is 0–10, stored as DECIMAL(5,3), and
  serialized as a JSON number. Platform ratings belong to reviews.
- genres, cast, and metadata_overrides default to empty JSON lists in
  the model. Direct-SQL writers must provide those arrays explicitly.
- Local genres/cast may have null provider identifiers.
- Date, JSON, numeric, and lifecycle attributes have Eloquent casts.
- Deactivation sets deactivated_at. Movie::active() excludes inactive
  rows; historical queries remain possible through normal Movie queries.
  Reactivation clears the timestamp and preserves both identities.

The migration adds source/identity, rating, and runtime CHECK constraints
on production MySQL. Model validation also protects SQLite test writes.
Use Eloquent saves for identity invariants: bulk query-builder updates
bypass model events. API/admin permissions, refresh transactions, override
management, and association checks belong to their respective tasks.
A fillable field is not an authorization decision.

## Verification

From backend:

    php artisan test --filter=MovieCatalogTest
    php artisan test

The default suite uses SQLite in memory. Its MySQL-only direct-SQL
constraint test is intentionally skipped there.

Implementation verification also ran all nine catalog tests on MySQL
9.4.0 in an isolated temporary database: 9 passed, 59 assertions. That
database was removed afterward; no migration was applied to the working
development database.

The relationship tests verify the ERD key definitions. Actual relationship
persistence/deletion tests must be added by G/H/I when their real models
and schemas are available.

After code review, run php artisan migrate against your configured
development database to add movies. Do not use migrate:fresh on a database
containing data you want to keep.
