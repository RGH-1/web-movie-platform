
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

Sentiment fields are server-managed. Clients cannot set `sentiment_label`, `sentiment_status`, or `sentiment_score` in POST/PATCH bodies.

## Response

POST and PATCH return one review under `data`:

```json
{
  "data": {
    "id": "17",
    "movie_id": "42",
    "rating": 4,
    "review_text": null,
    "sentiment_label": null,
    "sentiment_status": "skipped",
    "sentiment_score": null,
    "author": { "id": "8", "name": "Sam Example" },
    "created_at": "2026-11-12T12:00:00.000000Z",
    "updated_at": "2026-11-12T12:00:00.000000Z"
  }
}
```

IDs in the review response are decimal strings. Timestamps use UTC ISO 8601. Author data contains only the ID and display name. It may be null if the account disappears during the request.

### Asynchronous review sentiment — AB#141

Written reviews are analyzed asynchronously using **Azure AI Language — Sentiment Analysis** through a Laravel queued job. The API returns a review immediately; it does not wait for Azure to respond. Only review text is submitted to the sentiment provider, not ratings, user IDs, or account details.

- `sentiment_label`: `positive`, `neutral`, `negative`, or `null` when no classification has completed successfully. Azure can return `mixed`, which this implementation normalizes to `neutral` because the application supports three labels.
- `sentiment_status`: `pending`, `completed`, `failed`, or `skipped`.
- `sentiment_score`: nullable floating-point value in the interval `[-1, 1]`, calculated by the application as **Azure positive confidence minus Azure negative confidence**. This is a derived signed score, not a score supplied directly by Azure. It is `null` if valid confidence scores are unavailable.

A new review with non-empty text becomes `pending` until its queued analysis completes or fails. Rating-only, blank-text, and cleared-text reviews are `skipped` and have null labels and scores. Editing the written text invalidates the prior result and queues a new analysis; changing only the star rating does not requeue sentiment analysis. A stale job cannot overwrite the result of a newer text revision.

Provider timeouts, authentication or quota errors, and invalid responses mark analysis `failed` without rejecting or rolling back a valid review write. A `neutral` label does not inherently require the numerical score to be exactly zero; the score is derived independently from the positive and negative confidence values.

For setup, API configuration, worker commands, and verification, see [Sentiment analysis integration](../../services/sentiment/README.md).

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

The sentiment provider's HTTP errors are handled by the background job, not returned as review-write error responses.

## First review from TMDB Movie Details (AB#102)

When Movie Details supplies `local_movie_id`, the form creates the review directly. For an unmapped TMDB movie, submitting a valid form first calls `POST /api/v1/movies/tmdb/{tmdbId}/resolve` and then creates the review using the canonical ID from the response. Viewing Movie Details and invalid form submissions perform no catalog writes. A deactivated mapping remains unavailable for new reviews.

If resolution fails, the form keeps the draft and sends no review request. The form waits for `Retry-After` before retrying a rate-limited resolution. If resolution succeeds and the review write fails, the catalog row persists and a retry from the same form reuses its ID. A duplicate review still offers the reload-to-edit action. After a successful save, reopening Movie Details prefills the owner's review.

## Movie Details review management (AB#64)

Movie Details loads visible reviews by canonical `local_movie_id`. The platform score is displayed out of 5 with the backend's movie-wide rating count. The TMDB score is displayed separately out of 10.

Each review shows its rating, author, creation time, and optional text. Rating-only cards show “Rating only.” Author links use the public profile route. The viewer’s edit and delete controls come from the authenticated `viewer_review` field, so they work even when the viewer’s review is outside the visible page or hidden.

Deletion requires confirmation. After a successful create, edit, or delete, the review section reloads page 1 and its statistics from the API. For a first review imported through AB#102, the list uses the canonical movie ID returned by the successful submission.

## Administrator moderation — AB#147

Administrators can list reviews and hide or restore them through the
[administrator moderation API](admin-review-moderation-contract.md).

Public listings and rating statistics exclude hidden reviews. The private
viewer_review context remains available to the author for editing.
Moderation metadata is returned only by administrator endpoints.
