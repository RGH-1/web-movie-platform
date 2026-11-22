# Movie-night management API — AB#121

Send Accept: application/json. Writes use JSON and the existing Sanctum
session-cookie/CSRF flow.

All endpoints require authentication and a complete profile.

## Endpoints

| Method | Path | Behavior |
| --- | --- | --- |
| GET | /api/v1/movie-nights | Paginated nights visible to the caller |
| POST | /api/v1/movie-nights | Create an organized night |
| GET | /api/v1/movie-nights/{id} | Read authorized details |
| PATCH | /api/v1/movie-nights/{id} | Organizer update/scheduling |
| POST | /api/v1/movie-nights/{id}/cancel | Organizer cancellation |

Cancellation retains the night and its history.

## Visibility

- Organizers can read their nights in every status.
- Explicit participants can read scheduled, finalized, and cancelled nights.
- Drafts are organizer-only.
- Unrelated users, including administrators, receive 404 for private IDs.
- Visible participants receive 403 for organizer-only mutations.

Membership is stored in movie_night_participants, unique by
(movie_night_id, user_id).

Only trusted server logic grants membership. No join or participant-management
endpoint is introduced here.

The organizer is implicit and should not be inserted as an additional member.

Future invitation acceptance must create/reuse this membership. Pending,
declined, or revoked invitations must not grant access.

Revoking membership removes participant read access.

## Listing

Optional query parameters:

| Parameter | Default | Rules |
| --- | --- | --- |
| page | 1 | Integer 1–2147483647 |
| per_page | 20 | Integer 1–50 |
| status | Omitted | draft, scheduled, finalized, cancelled |

Unsupported query parameters receive 422.

Results are ordered by starts_at descending, then ID descending.
Pagination uses Laravel data/links/meta.

Filtering never expands the caller's visibility scope.
An out-of-range page returns an empty data array.

## Create and update

Example creation body:

```json
{
  "title": "Weekend movies",
  "description": "Bring snacks.",
  "starts_at": "2026-11-29T20:00:00+02:00",
  "timezone": "Asia/Beirut",
  "location": "Campus room 2",
  "meeting_url": null,
  "status": "draft"
}
```

POST requires title and starts_at.

- title: nonblank string, maximum 255 characters.
- description: nullable string, maximum 2000.
- starts_at: valid explicit-offset ISO date-time with seconds; must be future.
- timezone: named timezone; defaults to UTC on creation.
- location: nullable string, maximum 255.
- meeting_url: nullable HTTP/HTTPS URL, maximum 2048.
- status: optional draft or scheduled; defaults to draft.

Optional blank strings are normalized to null.

PATCH requires at least one accepted field. Omitted fields remain unchanged.
Explicit null clears description, location, or meeting_url.

New/changed start times must be future. Scheduling a draft also requires its
effective stored start time to be future.

Draft-to-scheduled is supported. Scheduled-to-draft is rejected.
Finalization and winner selection remain separate operations.

Organizer and winner IDs, timestamps, participant assignments, and unknown
body fields receive 422.

Status is validated separately and assigned by trusted service logic. It
remains excluded from model mass assignment.

Query parameters cannot supply creation/update body fields.

POST returns 201. PATCH returns 200.

## Cancellation

POST /api/v1/movie-nights/{id}/cancel accepts an empty body.

Cancellation is organizer-only and allowed from draft, scheduled, or finalized.
The response is 200 with the updated representation.

A repeated cancellation returns 403 under the existing V1 policy.

Cancellation preserves any existing winning movie.
Cancelled nights remain protected from edits and new vote/message writes.

## Representation

Every single-record response has a data object containing:

- id: movie-night ID as a decimal string.
- organizer: safe ID/name only.
- title, description, location, meeting_url.
- starts_at: UTC ISO 8601 timestamp.
- timezone: named display timezone.
- status.
- winning_movie_id: nullable canonical movie ID string.
- winning_movie: nullable safe catalog summary with local_movie_id,
  tmdb_id, source, title, and is_active.
- viewer_role: organizer or participant.
- permissions.update and permissions.cancel.
- summaries.participants.count: explicit membership-row count.
- summaries.invitations: null while invitation summaries are unavailable.
- summaries.shortlist: null while shortlist summaries are unavailable.
- created_at and updated_at: UTC ISO timestamps.

Example time conversion:

2026-11-29T20:00:00+02:00 becomes 2026-11-29T18:00:00.000000Z.

Storage has second precision. Timezone changes alone do not move the instant.

Null invitation/shortlist summaries mean unavailable functionality, not an
empty invitation list or empty shortlist.

All successful responses use Cache-Control: private, no-store.

## Errors

| Status | Meaning |
| --- | --- |
| 401 | Authentication required |
| 403 | Profile incomplete or visible actor lacks mutation permission |
| 404 | Missing, malformed, or privately inaccessible movie-night ID |
| 419 | Browser CSRF verification failed |
| 422 | Invalid body/query data or unsupported lifecycle transition |

Ownership and lifecycle checks are repeated on a freshly locked row for
updates and cancellation.

## Integration notes

- Preserve canonical IDs as strings.
- Do not use a TMDB ID as a movie-night or canonical winning-movie ID.
- Treat meeting/location/description values as plain data.
- The server does not fetch meeting URLs.
- Future invitation, shortlist, vote, and chat features must reuse the
  established visibility and write boundaries.
- Vote/message permissions are not broadened by this read-access task.

## Verification

New suites:

- MovieNightApiTest
- MovieNightMembershipTest

Existing coverage reused:

- MovieNightSchemaTest
- MovieNightAuthorizationTest
- ApiRouteAccessTest
- ProfileCompletionTest

| Check | Result |
| --- | --- |
| Focused movie-night tests | Pass |
| Existing auth/profile regressions | Pass |
| Full backend suite and skip reasons | Pass |
| Development membership migration | Pass |
| Browser session/CSRF create-update-cancel | Pass |
| Private access/member revocation | Pass |
| ISO timestamps and validation | Pass |

Record actual results and the tested commit before closing AB#121.