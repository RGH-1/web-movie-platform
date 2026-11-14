# Movie catalog ERD — AB#69

This diagram describes the target relationships. `users` already exists. Catalog, review, and movie-list tables are implemented. Other social and playback tables belong to their respective work items. Movie-list names follow AB#90.

```mermaid
erDiagram
    USERS ||--o{ REVIEWS : writes
    USERS ||--o{ WATCHLIST_ENTRIES : saves
    USERS ||--o{ MOVIE_LISTS : owns
    USERS |o--o{ REVIEWS : moderates
    MOVIE_LISTS ||--o{ MOVIE_LIST_ITEMS : contains
    MOVIES ||--o{ REVIEWS : receives
    MOVIES ||--o{ WATCHLIST_ENTRIES : appears_in
    MOVIES ||--o{ MOVIE_LIST_ITEMS : appears_in
    MOVIES ||--o{ MOVIE_MEDIA_ASSETS : has
    MOVIE_MEDIA_ASSETS ||--o{ WATCH_SESSIONS : supplies

    USERS {
        bigint id PK
    }
    MOVIES {
        bigint id PK
        int tmdb_id UK "Nullable"
        string source "tmdb or local"
        string title
        datetime tmdb_synced_at "Nullable"
        datetime deactivated_at "Nullable"
    }
    REVIEWS {
        bigint id PK
        bigint user_id FK
        bigint movie_id FK
        tinyint rating "1 through 5"
        text review_text "Nullable"
        string moderation_status "Defaults to visible"
        bigint moderated_by FK "Nullable"
        datetime moderated_at "Nullable"
        text moderation_reason "Nullable"
        datetime created_at
        datetime updated_at
    }
    WATCHLIST_ENTRIES {
        bigint id PK
        bigint user_id FK
        bigint movie_id FK
    }
    MOVIE_LISTS {
        bigint id PK
        bigint user_id FK
        string name
        text description "Nullable"
        boolean is_public "Default false"
        timestamp created_at
        timestamp updated_at
    }
    MOVIE_LIST_ITEMS {
        bigint movie_list_id PK, FK
        bigint movie_id PK, FK
        timestamp created_at
        timestamp updated_at
    }
    MOVIE_MEDIA_ASSETS {
        bigint id PK
        bigint movie_id FK
        string disk
        string object_key
        string mime_type
        datetime deactivated_at "Nullable"
    }
    WATCH_SESSIONS {
        bigint id PK
        bigint media_asset_id FK
    }
```

`movie_list_items` uses the `(movie_list_id, movie_id)` pair to prevent duplicate membership. Watchlist entries are unique by `(user_id, movie_id)`. All `movie_id` columns shown above are required and restrict deletion of the referenced movie. The playback session’s asset reference also restricts deletion. See the [catalog architecture](movie-catalog.md) for the full movie column definitions and lifecycle rules.
Reviews are unique by `(user_id, movie_id)`.
AB#90: deleting an owner cascades to their movie lists and items; deleting a list cascades to its items. Catalog movies are preserved. Logging out does not delete any of these records.
