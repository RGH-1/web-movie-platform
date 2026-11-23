# Review moderation (AB#148)

From an administrator's homepage, select **Review moderation**. The protected
page shows review text, author, movie, rating, and visibility. After an action,
the card shows the latest moderation reason, administrator, and time returned
by Laravel. Use **All reviews**, **Visible**, or **Hidden** to narrow results.
The list uses Laravel pagination.

Both **Hide review** and **Restore review** require confirmation and a reason
of 1 to 1000 characters. After Laravel confirms a change, the page refreshes
the active filter and moves to the last available page if the current page
no longer exists.

The page uses the [existing moderation API](../api/admin-review-moderation-contract.md):

- `GET /api/v1/admin/reviews?page=1`, optionally with `status=visible` or `status=hidden`.
- `PATCH /api/v1/admin/reviews/<reviewId>/moderation` with
  `{"status":"hidden","reason":"Contains spoilers."}`. Restore uses
  `"status":"visible"` and also requires a reason.

Requests send `Accept: application/json` and the existing Sanctum session
cookie. Writes use JSON and initialize Sanctum CSRF first.

## Browser verification

1. Open a movie with a written review as a regular account. Use a separate
   browser session to sign in as an administrator. From
   `http://localhost:5173/`, open **Review moderation**.
2. Select **Visible**. Find the review, verify the author, movie, and rating,
   and expand **Read review**. If the test database has more than 20 matching
   reviews, use **Next** and **Previous**.
3. Click **Hide review**, then submit with the reason blank. Check the
   validation message. Enter a reason and confirm. The review should leave
   the **Visible** results. Reload the public review listing as another
   signed-in user and check that the review is absent.
4. Switch to **Hidden** and check the recorded reason, moderator, and time.
   Click **Restore review**, enter a reason, and confirm. Check the public
   review listing again and confirm that the review has returned.
5. In the regular user's browser, open `/admin/reviews` directly and check
   the administrator access message. Signed-out visitors should reach login.
6. Block only `/api/v1/admin/reviews` in DevTools to test the load error.
   Unblock it and click **Try again**. Test a filter with zero results for
   the empty message and check that the cards fit a narrow viewport.
