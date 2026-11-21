# Administrator review moderation API — AB#147

## Authorization

Administrator access is controlled by the server-managed `users.is_admin`
boolean, which defaults to false.

The flag is excluded from User mass assignment and generic serialization.
Registration rejects administrator assignment, and profile updates reject
protected fields.

Administrator endpoints require Sanctum authentication and the
`access-admin` gate. ReviewPolicy additionally authorizes listing and
moderation through the dedicated Form Requests.

Browser mutations use the existing session-cookie and CSRF flow.

Administrator moderation access does not grant permission to edit or delete
another author's review through the owner-only review endpoints.

## Development administrator

Configure these values in the untracked `backend/.env`:

- DEV_ADMIN_NAME
- DEV_ADMIN_EMAIL
- DEV_ADMIN_PASSWORD

The password must contain at least 12 characters, at most 72 bytes, and no
NUL character.

From `backend`:

    php artisan config:clear
    php artisan db:seed --class=DevelopmentAdminSeeder

The seeder runs only in the local environment. It is invoked explicitly and
is not part of DatabaseSeeder.

It refuses to promote an existing non-admin account. Rerunning it for an
existing administrator preserves that account and its password.

No API endpoint grants or revokes administrator status.

## GET /api/v1/admin/reviews

Returns reviews across active and inactive catalog movies, including visible
and hidden reviews.

Query parameters:

| Parameter | Default | Accepted values |
| --- | --- | --- |
| page | 1 | Integer from 1 through 2147483647 |
| per_page | 20 | Integer from 1 through 50 |
| status | Omitted | visible or hidden |

Results use descending creation time, then descending review ID.
Responses use the existing Laravel data/links/meta pagination structure.
A page beyond the last page returns an empty data array.

Each entry includes the public review fields, including sentiment fields,
plus:

- movie.local_movie_id: canonical movie ID as a decimal string
- movie.title
- movie.source
- movie.tmdb_id: nullable provider ID
- movie.is_active
- moderation.status
- moderation.reason: nullable before moderation
- moderation.moderated_at: nullable UTC ISO 8601 timestamp
- moderation.moderator: nullable object containing only ID and name

Author and moderator email addresses, passwords, and internal sentiment
hashes are not returned.

## PATCH /api/v1/admin/reviews/{reviewId}/moderation

The path identifies the review, not the movie.

Send Accept: application/json and a JSON body:

    {
      "status": "hidden",
      "reason": "Contains spoilers."
    }

Both fields are required.

- status: visible or hidden
- reason: nonblank string, trimmed, maximum 1000 characters
- unexpected body fields: rejected
- query parameters cannot supply the action body

On a status transition, the server records:

- moderation_status
- moderated_by, from the authenticated administrator
- moderated_at, using server time
- moderation_reason

The response is 200 with the updated administrator review resource under
data.

Repeating the current status returns 200 without changing the stored reason,
moderator, timestamps, or review content. The request must still be valid.

A restore requires a reason and records the restoring administrator. The
existing columns retain the latest moderation action; they do not provide a
complete historical action log.

The action locks the current review row within a database transaction.
Moderation does not change review text, rating, ownership, or sentiment
fields and does not enqueue sentiment processing.

Successful administrator responses use Cache-Control: private, no-store.

## Public visibility

Public movie review listings and their count/average calculations include
only visible reviews. Public-profile review previews and review counts
exclude hidden reviews.

When no visible reviews remain, rating_count is 0 and rating_average is null.

The current popular/upcoming/discovery feeds contain TMDB movie cards rather
than platform reviews or platform rating aggregates.

Movie Details may retain the authenticated viewer's own hidden review in
viewer_review for owner editing. This is private context and does not make
the review public.

Author edits preserve moderation state. Hidden reviews continue to occupy
the unique author/movie review slot.

Moderator deletion clears the moderator foreign key while retaining the
stored status, reason, and moderation timestamp, following the existing
review schema.

## Errors

| Status | Meaning |
| --- | --- |
| 401 | Authentication required |
| 403 | Administrator authorization denied |
| 404 | Review absent or identifier invalid |
| 419 | Browser CSRF verification failed |
| 422 | Invalid query parameters or moderation body |

## Verification

New coverage:

- AdminAuthorizationTest
- AdminReviewModerationTest

Existing coverage reused:

- ProfileOwnershipTest
- ReviewApiTest
- ReviewModerationCompatibilityTest
- ReviewTest
- PublicProfileTest
- MovieDetailsReviewContextTest
- ReviewSentimentTest

PHPUnit blanks Azure sentiment credentials by default. Sentiment-specific
tests supply fake configuration and provider responses.

Execution results must be recorded before closing AB#147:

| Check | Result |
| --- | --- |
| Administrator authorization tests | Pending |
| Moderation API tests | Pending |
| Existing backend regressions | Pending |
| Full backend suite and skip reasons | Pending |
| Frontend tests, lint, and build | Pending |
| Browser session/CSRF verification | Pending |
| Guest and normal-user denial | Pending |
| Hide/restore audit metadata | Pending |
| Public listing, profile, and aggregate transitions | Pending |

Record the tested commit and distinguish automated output from manual
observations.