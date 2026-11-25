# Multi-user watch room and spoiler verification — AB#135

## Baseline and scope

Inspected baseline: 92e5059.

This task verifies independent playback, accepted-participant messaging,
private Reverb subscriptions, persistence, spoiler hiding, reconnection,
and media-error recovery.

U1–U5 supply the feature implementation. AB#135 adds integration coverage
and resets playback time/duration before retrying a failed video.

## Environment

| Item | Tested value |
| --- | --- |
| Tested commit | Pass |
| Date | Pass |
| Browser A/version | Pass |
| Browser B/version | Pass |
| Laravel/PHP | Pass |
| Node | Pass |
| Database | Pass |
| Room ID | Pass |
| Accepted participant IDs | Pass |
| Demo asset ID | Pass |

The repository's demo MP4 was inspected with ffprobe and has a 60-second
duration, matching VideoAssetSeeder. This does not establish browser playback.

Local live tests require BROADCAST_CONNECTION=reverb and matching backend
and Vite Reverb settings. The active local Vite file is .env.local.

Do not record passwords, cookies, auth signatures, or server secrets.

## Startup

From backend:

```powershell
php artisan config:clear
php artisan migrate
php artisan db:seed --class=VideoAssetSeeder
```

Run separate terminals:

```powershell
# backend
php artisan serve --host=localhost --port=8000

# backend
php artisan reverb:start --debug

# frontend
npm run dev
```

Use http://localhost:5173 consistently. A queue worker is unnecessary for
the current immediate chat notification event.

## Fixtures

Use a disposable finalized night with the registered 60-second clip.

Two accounts must accept real invitations and join in isolated browser
sessions. Keep a third invitee pending for denial checks. The organizer
can use a temporary setup session.

Use a temporary canonical local movie if needed. Keep fixture messages
below 50 for the refresh test.

## Existing coverage reused

- MovieNightMessageTest: persistence, sender identity, timestamps,
  pending/guest denial, revocation, validation and rate limiting.
- MovieNightBroadcastTest: channel authorization and notification dispatch.
- MovieNightVideoTest: assignment authorization and ranged streaming.
- MovieNightInvitationTest: invitation/RSVP behavior.
- MovieNightAuthorizationTest: lifecycle and guarded writes.
- CrossFeatureAuthorizationTest: cross-room and disabled-account boundaries.
- movieNightMessages.test.jsx: cursor catch-up, deduplication, draft retention,
  timestamp visibility, manual reveal and explicit spoilers.

## Added coverage

- watchRoom.test.jsx: local playback/spoiler independence, actual video-ref
  timestamp capture, retry state reset, unassigned clips and video-load errors.
- movieNightBroadcast.test.jsx: private authorization wiring, subscription
  refresh, reconnect status, failure handling and cleanup.

These use mocked transports/media. They do not replace the browser test.

## Automated results

From backend:

```powershell
php artisan test --filter='MovieNightMessageTest|MovieNightBroadcastTest|MovieNightVideoTest|MovieNightInvitationTest|MovieNightAuthorizationTest|CrossFeatureAuthorizationTest'
```

From frontend:

```powershell
npm run test:ui -- tests/ui/watchRoom.test.jsx tests/ui/movieNightBroadcast.test.jsx tests/ui/movieNightMessages.test.jsx
npm test
npm run lint
npm run build
```

| Check | Result/evidence |
| --- | --- |
| Focused backend suites | Pass |
| Focused frontend suites | Pass |
| Full frontend suite | Pass |
| Lint | Pass |
| Production build | Pass |

Record skipped tests and their reasons.

## Browser scenarios

| ID | Required observation | Result/evidence |
| --- | --- | --- |
| WR-01 | Both accepted users stream and privately subscribe | Pass |
| WR-02 | Play, pause and seeking remain independent | Pass |
| WR-03 | Bidirectional live chat, correct authors, no duplicates | Pass |
| WR-04 | Future text hides, appears at its time, and re-hides on rewind | Pass |
| WR-05 | Explicit spoilers require separate manual reveal | Pass |
| WR-06 | Message IDs/content/flags persist across refresh | Pass |
| WR-07 | Persist while Reverb is down; manual reload and automatic catch-up recover | Pass |
| WR-08 | Pending/anonymous REST and channel access denied | Pass |
| WR-09 | Declining membership denies later reads/writes/subscriptions | Pass |
| WR-10 | Actual media failure recovers with safe playback/spoiler state | Pass |

For live delivery, record a successful private subscription, a message.created
WebSocket notification, the resulting authorized history fetch, and the
receiving browser updating without manual reload.

For persistence, compare message IDs before and after refresh.
For denial, record HTTP status codes and confirm the denied message was not stored.

## Behavior boundaries

Playback is local; the implementation does not synchronize play/pause/seek.
Refreshing the room resets local playback and manual reveal state.

Spoiler hiding is a UI convenience. Authorized clients receive full message
text through REST. Reverb notifications themselves contain no message text.

Video-error recovery remounts chat. Stored messages persist; unsent drafts
and reveal overrides may reset.

The history endpoint is cursor-paginated. Initial rendering loads the latest
page; reconnection follows cursors to catch up to the last synchronized ID.

## Cleanup and completion

Delete only the captured disposable night and fixture movie.
Keep the shared demo video asset/file and existing accounts.

AB#135 remains pending until the required browser scenarios have recorded
outcomes. Mocked tests alone do not establish real playback or Reverb delivery.