# Following feed (AB#105)

Open **Following feed** from the homepage while signed in. React reads
`GET /api/v1/feed?page=<n>` through the shared Axios client. It sends
`Accept: application/json` and the existing Sanctum session cookie, with no
request body. Laravel determines which activity is visible and its order.

Each card identifies the activity as a **Rating**, **Written review**, or
**Public list**. The author links to the public profile. Ratings and reviews
link to the movie and show the rating out of five. The feed API does not send
review text. Public lists link to list details.

## Verify locally

1. Run the project's pending migrations against your local development database
   with `php artisan migrate`.
2. Sign in as the account whose activity you’ll test—the fixture author. In profile settings, enable and save all four switches:
   - Share activity — the master switch.
   - Share rating-only activity.
   - Share written-review activity.
   - Share public-list activity.
3. On the movie details page with A's written review, click A's name on the
   review card and copy the public profile URL from the address bar. Open that
   URL as account B in a separate browser session and select **Follow**.
   Open **Following feed** from the homepage. Check the
   three activity types, the timestamp, the scores, and that the private list
   is absent. Open the author, movie, and public-list links.
4. Seed or create at least 13 visible activities from followed accounts. Go to
   `/feed` and use **Next** and **Previous**. While still signed in, load the
   homepage before applying DevTools network throttling, then open the feed to
   check **Loading activity…**. Block only the feed request, open the feed again,
   unblock the request, and select **Try again**.
5. Open the feed as an account that follows nobody to see the empty state.
   Check that the cards fit a narrow phone viewport and a desktop viewport.

## Privacy — AB#106

Feed inclusion uses each author's persisted master and category settings.
Filtering happens on the server before pagination. Viewer-supplied
settings cannot override author preferences.

See [Public activity privacy](../api/activity-privacy.md).
