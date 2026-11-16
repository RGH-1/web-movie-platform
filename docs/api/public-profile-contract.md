# Public profiles

`GET /api/v1/users/{userId}/profile` accepts anonymous requests. Authentication
and profile completion are not required. Owners receive the same public data
as guests. Existing `id` and `name` fields remain compatible.

Example response (`200`):

```json
{
  "data": {
    "id": "8",
    "name": "Review author",
    "statistics": { "reviews_count": 1, "public_lists_count": 1 },
    "followers_count": 0,
    "following_count": 0,
    "reviews": [{
      "id": "21",
      "movie_id": "5",
      "rating": 4,
      "review_text": "A favorite.",
      "author": { "id": "8", "name": "Review author" },
      "created_at": "2026-11-16T10:00:00.000000Z",
      "updated_at": "2026-11-16T10:00:00.000000Z"
    }],
    "public_lists": [{
      "id": "3",
      "name": "Favorites",
      "description": null,
      "movies_count": 2
    }]
  }
}
```

- All IDs are strings. Counts are nonnegative integers. `name` is the user's
  display name. Review text and list description may be null.
- Review totals include only this user's visible reviews on active movies.
  Hidden reviews and reviews on deactivated movies are excluded from both
  totals and previews. Reviews use the existing explicit review resource.
- List totals and previews include only this user's public lists. Each summary
  includes the number of list entries, consistent with the list API.
- Both previews contain at most 10 entries, newest creation first, with
  descending ID as the tie breaker. Statistics count all qualifying entries,
  including those beyond the preview. Empty collections are `[]` and counts
  are zero. This endpoint has no pagination parameters.
- Follow counts come from directed relationships in `user_follows`:
  `follower_id` follows `followed_id`. `followers_count` counts incoming
  relationships and `following_count` counts outgoing relationships. Duplicate
  pairs are prevented by the composite primary key; deleting either user
  removes the relationship. Self relationships are excluded from both counts.
  Counts are zero for users without relationships. Follower identities and
  social login accounts are not returned.
- Explicit API resources allowlist every response field. Email, password,
  tokens, social accounts, private lists, watchlists, watched history, account
  preferences, and moderation metadata are never exposed. Public totals do
  not incorporate private activity.

Unknown IDs and invalid IDs (including zero, leading zeros, nonnumeric values,
and integer overflow) return `404` with `message: "Profile not found."` for
JSON requests. No authentication redirect is issued. Clients should request
`Accept: application/json` and handle the status and message; framework debug
metadata is not part of the contract.

Verification: run `php artisan test --filter=PublicProfileTest` from `backend`.

Deployment: run `php artisan migrate` to create `user_follows` before serving
this endpoint. Application code can maintain relationships through
`$user->following()->syncWithoutDetaching([$targetId])` and
`$user->following()->detach($targetId)`. Follow/unfollow HTTP operations are
outside this public read endpoint's contract.
