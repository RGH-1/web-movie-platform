# My Movie Nights (AB#122)

**My Movie Nights** appears on the signed-in homepage. Open it to view events
you organize and events you can attend. The list uses Laravel pagination.
Open an event title to see status, organizer, scheduled time, confirmed
participant count, and the optional meeting link or location.

The form uses device-local date and time. Creation saves the UTC instant
and the device's named time zone. Details display the time in the saved
event time zone. Editing another field leaves the stored instant intact,
including its seconds. A scheduled night cannot return to draft.

The existing API exposes a confirmed participant count. Individual invitees,
RSVP, voting, and watch room depend on separate features and retain
unavailable entry points.

AB#144 enables calendar export for scheduled and finalized nights.
Each download refetches authorized details before generating the file.
Export is disabled during editing/cancellation, and organizer mutations
are disabled while the export refresh is pending.

See [Calendar export](calendar-export.md) for behavior and verification.

## Manual verification

Start Laravel and Vite with the project's configured development database.
Run pending migrations from the backend directory with `php artisan migrate`.

1. Sign in as an account with a complete profile. From
   `http://localhost:5173/`, choose **My Movie Nights** and select
   **Plan a movie night**. Click **Save movie night** with the title and time
   blank. Both fields should show feedback.
2. Fill in a title, tomorrow's date and time, an optional location, and an
   HTTP or HTTPS meeting link. Choose **Draft** and save. The details page
   should show your organizer name, the time and time zone, Draft status, and
   the meeting link. Copy the details URL.
3. Click **Edit movie night**. Change the location and set **Status** to
   **Scheduled**. Save. The status should change and the start time should
   stay the same.
4. In a separate browser session, sign in as a complete-profile account that
   is not a participant. Open the copied URL. It should show
   **Movie night not found or unavailable to your account**. With a scheduled
   test event that already has a participant membership, repeat as that
   participant. They should see the details without Edit or Cancel controls.
5. As the organizer, open **Cancel movie night** and click **Keep movie night**
   to leave the event scheduled. Open the confirmation again and choose
   **Confirm cancellation**. The status should change to **Cancelled**, and the
   event should still appear in My Movie Nights.
6. Test at a narrow phone width and on desktop. To check loading and retry,
   block the `/api/v1/movie-nights` request in DevTools, then load the page,
   remove the block, and click **Try again**.

| Action | Request |
| --- | --- |
| Browse | `GET /api/v1/movie-nights?page=1` |
| Create | `POST /api/v1/movie-nights` |
| View | `GET /api/v1/movie-nights/<id>` |
| Edit | `PATCH /api/v1/movie-nights/<id>` |
| Cancel | `POST /api/v1/movie-nights/<id>/cancel` with an empty body |

All requests send `Accept: application/json` and the existing Sanctum session
cookie. The React service initializes CSRF before every write. Example create
body for a future date:

```json
{
  "title": "Weekend movies",
  "description": null,
  "starts_at": "2027-12-01T18:00:00.000Z",
  "timezone": "Asia/Beirut",
  "location": "Room 2",
  "meeting_url": "https://example.com/meet",
  "status": "draft"
}
```

See the [Laravel contract](../api/movie-nights-contract.md) for visibility,
status transitions, and validation.
