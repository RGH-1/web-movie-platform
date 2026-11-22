# Movie catalog ERD — AB#69

This diagram combines implemented catalog, review, movie-list, user-status,
and movie-night relationships with planned media/playback relationships.
Movie-night storage is introduced by AB#120. Invitation, voting, and chat
tables belong to their respective work items.

```mermaid
erDiagram
    USERS ||--o{ REVIEWS : writes
    USERS ||--o{ USER_MOVIE_STATUSES : saves
    USERS ||--o{ MOVIE_LISTS : owns
    USERS ||--o{ MOVIE_NIGHTS : organizes
    MOVIES |o--o{ MOVIE_NIGHTS : wins
    USERS ||--o{ MOVIE_NIGHT_PARTICIPANTS : participates
    MOVIE_NIGHTS ||--o{ MOVIE_NIGHT_PARTICIPANTS : grants_access

    USERS |o--o{ REVIEWS : moderates
    MOVIE_LISTS ||--o{ MOVIE_LIST_ITEMS : contains
    MOVIES ||--o{ REVIEWS : receives
    MOVIES ||--o{ USER_MOVIE_STATUSES : appears_in
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
    USER_MOVIE_STATUSES {
    bigint id PK
    bigint user_id FK
    bigint movie_id FK
    tinyint in_watchlist "Defaults to false"
    timestamp watched_at "Nullable"
    timestamp created_at
    timestamp updated_at
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
    MOVIE_NIGHTS {
        bigint id PK
        bigint organizer_id FK
        string title
        text description "Nullable"
        datetime starts_at "UTC"
        string timezone
        string location "Nullable"
        text meeting_url "Nullable"
        string status "Default draft"
        bigint winning_movie_id FK "Nullable"
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
    MOVIE_NIGHT_PARTICIPANTS {
        bigint movie_night_id PK, FK
        bigint user_id PK, FK
        timestamp created_at
        timestamp updated_at
    }
```

`movie_list_items` uses the `(movie_list_id, movie_id)` pair to prevent duplicate membership.
User movie statuses are unique by `(user_id, movie_id)`. Each record independently tracks watchlist membership through `in_watchlist` and watched status through `watched_at`. Records with neither status are removed by the Eloquent model's cleanup logic.
All `movie_id` columns shown above are required and restrict deletion of the referenced movie. The playback session’s asset reference also restricts deletion. See the [catalog architecture](movie-catalog.md) for the full movie column definitions and lifecycle rules.
Reviews are unique by `(user_id, movie_id)`.
AB#90: deleting an owner cascades to their movie lists and items; deleting a list cascades to its items. Catalog movies are preserved. Logging out does not delete any of these records.

Movie-night organizers and winning movies use restricted deletion.
`winning_movie_id` is nullable and references canonical `movies.id`.

See [movie-night storage and authorization](movie-nights.md) for lifecycle,
time handling, permissions, and the future vote/message write boundary.


AB#121 adds trusted participant membership, unique by
`(movie_night_id, user_id)`. Deleting a night or participant removes its
membership rows. Membership grants read access to non-draft nights; it
does not itself implement invitations, voting, or chat.