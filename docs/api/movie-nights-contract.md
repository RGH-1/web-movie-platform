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
| POST | /api/v1/movie-nights/{id}/invitations | Organizer invites a user |
| GET | /api/v1/movie-nights/{id}/invitations | Read invitations grouped by status |
| PATCH | /api/v1/movie-nights/{id}/rsvp | Invitee responds to their own invitation |
| POST | /api/v1/movie-nights/{id}/candidates | Propose a catalog movie |
| GET | /api/v1/movie-nights/{id}/candidates | Read the shortlist and vote totals |
| PUT | /api/v1/movie-nights/{id}/vote | Cast or replace the caller's vote |
| POST | /api/v1/movie-nights/{id}/finalize | Organizer chooses the winner |

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

Accepting an invitation creates or reuses this membership. Changing an accepted
RSVP to declined removes the membership. Invitations in pending or declined
status do not grant access to event details.

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

### Messages

Both message endpoints require an authenticated organizer or accepted participant.
Pending invitees and nonparticipants receive `404`. A room member can send while
the room is scheduled or finalized and can still read history after cancellation.

- `GET /api/v1/movie-nights/{id}/messages` returns up to 50 messages in
  descending ID order. Follow `links.next` to load older messages.
- `POST /api/v1/movie-nights/{id}/messages` accepts `text` (1–1,000 characters
  after trimming), optional `is_spoiler` (defaults to `false`), and optional
  `video_timestamp_seconds`. A non-null playback timestamp can be fractional
  and must fall between zero and the assigned video's duration, inclusive.
  Invalid fields return `422`. Sends are limited to 20 attempts per minute
  per account. Excess attempts return `429` with `Retry-After`.

A successful send returns `201` with the saved message under `data`. History
uses the same message shape, including string `id`, `movie_night_id`, and
`sender_id`, the sender's display name in `sender_name`, the submitted fields,
and ISO 8601 `created_at` and `updated_at` timestamps.

## Invitations and RSVP (AB#124)

- POST accepts a body with `invitee_id` set to an existing user ID and returns
  201 with a pending invitation in `data`. Duplicate invitations and
  self-invitations return 422.
- GET returns `data.pending`, `data.accepted`, and `data.declined` arrays.
  The organizer sees every invitation. Invitees see only their own
  invitation. Callers without an invitation or organizer access receive 404.
- PATCH accepts a body with `status` set to `accepted` or `declined` and
  returns 200 with the updated invitation in `data`. The authenticated
  invitee can repeat or change their response. Invalid statuses and extra body
  fields return 422.
- Organizers can prepare invitations while the night is a draft. Invitees can
  see and respond to invitations once the event is scheduled. Cancellation
  preserves invitation history for the organizer and each invitee and blocks
  new invitations and RSVP changes with 403 for callers with access.
- Invitation records expose string `id`, `movie_night_id`, and `inviter_id`,
  the invitee's string `id` and `name`, `status`, and ISO timestamps
  `created_at` and `updated_at`.
- Each invitation also includes `movie_night_preview`: a whitelisted event
  summary (`id`, title, description, starts_at, timezone, location,
  meeting_url, status, and safe organizer ID/name). This is intentionally
  available only through the invitation endpoint to the organizer or the
  invitee; it does not grant pending/declined invitees access to the complete
  protected movie-night resource or its participants, shortlist, or votes.

### My invitation inbox

`GET /api/v1/me/movie-night-invitations` returns invitations received by the
authenticated user, newest first. It accepts bounded `page` and `per_page`
(1–50) query parameters and returns the standard paginated `data`, `links`,
and `meta` response. Each data item uses the invitation representation above,
including its whitelisted `movie_night_preview`.

The server derives the invitee solely from the authenticated session. It never
accepts an invitee/user filter, excludes draft movie nights, includes cancelled
invitations as read-only history, and does not grant access to full movie-night
details. Accepted invitations remain in the inbox as history and also appear in
the user's normal My Movie Nights list.

## Shortlist and voting (AB#125)

- During the scheduled state, the organizer and accepted participants may
  propose any active canonical movie once per night. POST candidates accepts
  `movie_id` from the local catalog and returns 201.
- PUT vote accepts `candidate_id` from the same night's shortlist. Each
  eligible voter has one ballot per night. Repeating the request or voting for
  another candidate replaces that ballot. Declining an accepted invitation
  while voting is open removes the vote and retains any proposals.
- POST finalize is organizer-only. Send an empty body to finalize a sole
  leader, or send `candidate_id` to select a leader explicitly. A tie requires
  an explicit selection. With zero votes, all active candidates share the lead.
  Finalization requires at least one active candidate.
- Deactivated movies remain in the shortlist and retain their recorded votes.
  Only active candidates can receive new votes or win.
- Finalization saves the canonical winner and closes proposals and voting.
  Subsequent finalization and vote requests receive 403. The shortlist remains
  readable to authorized viewers, including after cancellation.
- All four endpoints return the shortlist and voting summary in `data`.
  The summary includes `status`, `voting_open`, `winning_movie_id`,
  `viewer_candidate_id`, `total_votes`, `leading_candidate_ids`, and
  `candidates`. Leader IDs are provided while voting is open. Each candidate
  includes `id`, `proposed_by`, `movie` (the canonical movie summary),
  and `vote_count`. Candidate, user, and canonical movie IDs are strings.

## Representation

## Watch room video assets (AB#130)

Video assets are licensed/self-created demo MP4 files stored on Laravel's private
`local` disk (for example `storage/app/private/video-assets/demo.mp4`), never in
the public web root. No demo media file is bundled: create a `video_assets`
record with the private relative path and duration before using the Watch Room.

- `GET /api/v1/movie-nights/{id}/video-assets` lists safe asset metadata for an
  eligible organizer.
- `PUT /api/v1/movie-nights/{id}/video` accepts `{ "video_asset_id": "…" }`.
  A finalized night with a winner is required; the organizer explicitly chooses
  a clip and no catalog movie is mapped automatically.
- `GET /api/v1/movie-nights/{id}/video` returns selected metadata and a protected
  stream URL without disclosing filesystem paths.
- `GET /api/v1/movie-nights/{id}/video/stream` is cookie-authenticated and
  supports HTTP byte ranges for native HTML5 seeking.

Existing movie-night visibility authorizes organizers and accepted participants;
guests, declined invitees, and unrelated users are denied. Playback remains
entirely independent per browser, with no localStorage, synchronization, or
WebSockets. Missing selected files return 404.

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
- summaries.invitations: null. Read invitation records from the invitations endpoint.
- summaries.shortlist: null. Read shortlist and totals from the candidates endpoint.
- created_at and updated_at: UTC ISO timestamps.

Example time conversion:

2026-11-29T20:00:00+02:00 becomes 2026-11-29T18:00:00.000000Z.

Storage has second precision. Timezone changes alone do not move the instant.

Null invitation/shortlist summaries mean the movie-night representation omits
those summaries.

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
- Shortlist and vote operations reuse movie-night visibility and the
  transactional write boundary.

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

## Calendar export consumer — AB#144

The existing React planner refetches authorized details before generating
a calendar file. Current scheduling, status, and visibility rules apply.

Files are generated in the browser. No calendar API endpoint or public
event URL is introduced.

See [Calendar export](../frontend/calendar-export.md).
