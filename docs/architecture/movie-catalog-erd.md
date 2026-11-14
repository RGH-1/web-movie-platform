# Movie catalog ERD — AB#69

This diagram describes the target relationships. `users` already exists. Catalog, social, and playback tables are implemented by their respective work items.

```mermaid
erDiagram
    USERS ||--o{ REVIEWS : writes
    USERS ||--o{ USER_MOVIE_STATUSES : saves
    USERS ||--o{ CUSTOM_LISTS : owns
    USERS |o--o{ REVIEWS : moderates
    CUSTOM_LISTS ||--o{ LIST_MOVIES : contains
    MOVIES ||--o{ REVIEWS : receives
    MOVIES ||--o{ USER_MOVIE_STATUSES : appears_in
    MOVIES ||--o{ LIST_MOVIES : appears_in
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
    USER_MOVIE_STATUSES {
    bigint id PK
    bigint user_id FK
    bigint movie_id FK
    tinyint in_watchlist "Defaults to false"
    timestamp watched_at "Nullable"
    timestamp created_at
    timestamp updated_at
    }
    CUSTOM_LISTS {
        bigint id PK
        bigint user_id FK
    }
    LIST_MOVIES {
        bigint list_id PK, FK
        bigint movie_id PK, FK
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

`list_movies` uses the `(list_id, movie_id)` pair to prevent duplicate membership. User movie statuses are unique by (user_id, movie_id). Each record independently tracks watchlist membership through in_watchlist and watched status through watched_at. Records with neither status are removed by the Eloquent model's cleanup logic. All `movie_id` columns shown above are required and restrict deletion of the referenced movie. The playback session’s asset reference also restricts deletion. See the [catalog architecture](movie-catalog.md) for the full movie column definitions and lifecycle rules.
Reviews are unique by `(user_id, movie_id)`.