# Movie reviews and ratings API — AB#62

Send `Accept: application/json`. Send JSON for POST and PATCH. Writes use the existing Sanctum authentication and completed-profile rules. Browser clients use the existing Sanctum CSRF flow.

| Method and path | Access | Success |
| --- | --- | --- |
| `GET /api/v1/catalog/movies/{movieId}/reviews` | Public | `200` with visible reviews and platform rating statistics |
| `POST /api/v1/catalog/movies/{movieId}/reviews` | Signed in with a complete profile | `201` with the created review |
| `PATCH /api/v1/reviews/{reviewId}` | Review owner with a complete profile | `200` with the updated review |
| `DELETE /api/v1/reviews/{reviewId}` | Review owner with a complete profile | `204` with an empty body |

`movieId` is the canonical `movies.id`. `reviewId` is the review's `id`. Both URL segments use positive decimal digits without leading zeroes, up to `9223372036854775807`. GET and POST require an active catalog movie. Owners can manage existing reviews for deactivated movies. An owner edit preserves the review's moderation status.

## Write body

POST requires `rating` as a JSON integer from 1 through 5. `review_text` is optional plain text up to 2,000 characters after trimming. Omitted, null, or blank text is stored as null on creation. PATCH requires at least one of the two fields. Omission preserves the existing value, and sending `review_text: null` or blank text clears it. Ownership, movie identity, and moderation fields are assigned by the server. Unexpected body fields receive `422`.

## Response

POST and PATCH return one review under `data`:

```json
{
  "data": {
    "id": "17",
    "movie_id": "42",
    "rating": 4,
    "review_text": null,
    "author": { "id": "8", "name": "Sam Example" },
    "created_at": "2026-11-12T12:00:00.000000Z",
    "updated_at": "2026-11-12T12:00:00.000000Z"
  }
}
```

IDs in the review response are decimal strings. Timestamps use UTC ISO 8601. Author data contains only the ID and display name. It may be null if the account disappears during the request.

GET returns an array of these reviews under `data`, newest first, with ties ordered by descending review ID. The optional `page` query parameter defaults to `1` and accepts integers through `2147483647`. Each page contains up to 10 reviews. The response includes Laravel pagination `links` and `meta`, including `meta.current_page`, `meta.last_page`, `meta.per_page`, and `meta.total`.

`meta.rating_count` counts all visible ratings for the movie, including rating-only submissions. `meta.rating_average` is the average over the same rows, rounded to two decimal places. These statistics cover the whole movie rather than the requested page. A movie with no visible ratings has `rating_count: 0` and `rating_average: null`. A page beyond the end returns `data: []` and retains the movie-wide statistics. Hidden reviews are excluded from both the list and the statistics.

## Errors

| Status | Meaning |
| --- | --- |
| `401` | The write request requires authentication. |
| `403` | The caller does not own the review, or the account has an incomplete profile. Incomplete profiles receive `PROFILE_INCOMPLETE`. |
| `404` | Malformed or missing resource ID, or an inactive movie on GET or POST. |
| `409` | The user already has a review for this movie, including a hidden review. Code `REVIEW_ALREADY_EXISTS`. |
| `419` | Failed CSRF verification for a state-changing SPA request. |
| `422` | Invalid body or pagination. Laravel includes field errors under `errors`. |