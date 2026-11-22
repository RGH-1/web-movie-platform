# Recommendation service implementation — AB#114

`GET /api/v1/recommendations` implements the `weighted-v1` contract in
[the recommendation API contract](recommendations-contract.md). It requires
Sanctum authentication and a complete profile, always derives the user from
the access token, and is limited to ten requests per minute per user.

The endpoint accepts only `limit` (1–20, default 12), `latitude`, and
`longitude`; coordinates must be supplied as a pair. It returns
`Cache-Control: private, no-store` and never returns supplied coordinates.

Candidate generation is bounded: at most 200 active local catalog cards and
at most 60 TMDB cards (one genre-discovery page, one popular page, and one
similar-movie page, each capped at 20). Local catalog metadata takes
precedence over TMDB mappings. Watched or reviewed movies (including hidden
reviews), future releases, inactive mappings, blank titles, and duplicates
are excluded. Watchlist-only records remain eligible.

The scorer applies the documented six weighted signals. It reads at most 100
watched statuses and 20 positive visible reviews; hidden reviews do not
contribute to preference or community-rating values. When no coordinates are
provided, it does not call the weather provider. Provider failure or missing
TMDB configuration is reported in `meta.warnings` while usable local cards
continue to be returned.

TMDB discovery responses are cached for 15 minutes, similar-movie responses
for 60 minutes, and normalized detail enrichment for 24 hours. Misses use a
single-flight cache lock; a failed provider call enters a 60-second cooldown
(or the provider's longer `Retry-After`). There are no cached personalized
responses. Shared application budgets allow 120 TMDB attempts and 60 weather
attempts per minute; cache hits do not consume either. Production must use a
shared, atomic cache/lock driver such as Redis—an in-memory or file cache
cannot enforce those limits across multiple PHP workers.

An eight-second external-I/O window accepts no new provider call with fewer
than 500ms remaining. Each recommendation TMDB call has a two-second
connection/request timeout, with no automatic retries. Provider cards are
pre-scored, then at most three unmapped cards are enriched with TMDB details
and credits before final scoring. The API contract remains **proposed**; this
implementation does not mark AB#112 as approved.

Run the focused checks from `backend`:

```bash
php artisan test --filter=RecommendationScorerTest
php artisan test --filter=RecommendationApiTest
php artisan test
```


## Favorite genre preferences — AB#115

The service reads the authenticated user's persisted favorite_genres.
Profile GET/PATCH uses supported TMDB integer IDs, such as [878, 9648].

RecommendationGenreNormalizer converts these to internal slugs using
App\Support\MovieGenres. Existing candidate selection, genre scoring,
provider genre discovery, and genre_preference explanations reuse that
normalized context.

Profile preference updates require no provider requests. Personalized
recommendation responses are not cached, so subsequent requests use the
updated preferences. Shared provider caches remain unchanged.

FavoriteGenrePreferencesTest verifies profile persistence and a reversal
of final ranking after changing saved preferences.