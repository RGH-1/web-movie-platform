# Calendar export — AB#144

## Integration

AB#144 extends the existing AB#122 movie-night planner.

- My Movie Nights retains its listing and creation flow.
- Movie-night details retain editing, cancellation, participant information,
  winner display, and existing error handling.
- Calendar export replaces the details page's unavailable calendar entry.
- Existing TMDB and local movie details offer release reminders.

No routes, backend endpoints, database migrations, or packages are added.
The existing movieNights service and planner utilities remain unchanged.

## Movie-night exports

Scheduled and finalized nights support .ics downloads.

Each download refetches the current authorized details through the existing
getMovieNight service. A changed schedule is used immediately. A cancelled
event or denied refresh creates no file.

Authentication/profile/private-access errors reuse MovieNightError.
Access failures clear the previously displayed event details.

Calendar export is disabled while editing or confirming cancellation.
Edit and Cancel are disabled while an export refresh is pending, preventing
that response from overwriting a newer local mutation.

Repeated clicks are blocked. Pending work is aborted on unmount.

The file includes title, description, UTC start, named event timezone,
optional location/meeting URL, and last-modified time.

DTSTART is UTC with Z. The named timezone and formatted local start are
retained in DESCRIPTION. No TZID parameter is attached to UTC DTSTART.

The schema has no end time. The export is a start-time reminder without
an invented duration.

## Release exports

Valid releases dated today or later offer all-day reminders.
Eligibility compares calendar dates using the current UTC date.

The source release_date remains a date. DTSTART uses VALUE=DATE and DTEND
is the exclusive next day. No local-time conversion is applied.

TMDB and local identities remain distinct. Canonical IDs stay strings.

The action uses already-loaded movie details and performs no catalog write.

## Format and limitations

Files use UTF-8 text/calendar, CRLF endings, escaped text, and content lines
folded at 75 bytes without splitting Unicode characters.

UIDs remain stable for the same identity and backend origin.
DTSTAMP records export time.

Downloads are snapshots, not subscriptions. Later updates/cancellations
do not automatically modify imported events. Reimport behavior depends
on the calendar application.

No organizer email, attendees, credentials, or invitation command is added.

## Automated verification

From frontend:

```powershell
node --test tests/calendar.test.js
npm run test:ui -- tests/ui/calendarActions.test.jsx tests/ui/calendarDownload.test.jsx tests/ui/movieNights.test.jsx tests/ui/movieDetails.test.jsx tests/ui/movieDetailsNavigation.test.jsx tests/ui/movieDetailsIntegration.test.jsx
npm test
npm run lint
npm run build
```

From backend, existing authorization regressions:

```powershell
php artisan test --filter='MovieNightApiTest|MovieNightAuthorizationTest|MovieNightMembershipTest'
```

## Manual verification

- Preserve planner creation, editing, cancellation and pagination.
- Verify scheduled organizer and participant exports.
- Verify draft/cancelled restrictions and fresh schedule/status checks.
- Revoke participant membership while details are open; export must fail
  without a file and remove stale details.
- Verify authentication/profile errors use existing guidance.
- Test mutation/export interlocks, duplicate clicks, offline retry and
  navigation during preparation.
- Test TMDB and local-only release reminders.
- Import into Google Calendar and Outlook; test Apple Calendar if available.
- Check Unicode, punctuation, multiline text, location and meeting link.
- Verify equivalent timed instants across calendar timezones and DST.
- Verify all-day dates do not shift in timezones west of UTC.

Use temporary calendars and captured database fixtures; clean them up afterward.

## Verification record

Tested commit: Pending.

| Check | Result |
| --- | --- |
| Calendar serializer tests | Pass |
| Calendar UI/download tests | Pass |
| Existing planner regressions | Pass |
| Existing movie details regressions | Pass |
| Full frontend suite | Pass |
| Lint and production build | Pass |
| Existing V2 authorization tests | Pass |
| Browser privacy/interlock checks | Pass |
| Google Calendar import | Pending |
| Outlook import | Pending |
| Apple Calendar import or availability | Pending |
| DST and all-day date checks | Pending |

Record actual results, tested application versions, and limitations.

## References

- [RFC 5545](https://www.rfc-editor.org/rfc/rfc5545)
- [Google Calendar import](https://support.google.com/calendar/answer/37118?hl=en)
- [Outlook import](https://support.microsoft.com/en-us/outlook/import-or-subscribe-to-a-calendar-in-outlook-com-or-outlook-on-the-web)
- [Apple Calendar import](https://support.apple.com/en-am/guide/calendar/icl1023/mac)