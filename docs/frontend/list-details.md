# List details and Add to List — AB#93

## Behavior and scope

My Lists names link to the protected `/lists/:listId` route. The same URL can be
shared with another signed-in user when a list is public. The API still decides
access: private/missing/non-owned targets return 404; the UI shows one generic
unavailable message. Guests go to login with their destination preserved. Public
lists do not appear in another user's My Lists and have no removal/management controls.
A public-list discovery screen and profile integration are not part of this task.

`ListDetailsPage.jsx` displays author, name, description, visibility, total movie count,
and paginated movie cards. Only the owner sees Remove controls. Removal changes
membership only, refetches authoritative data, and recovers when the last page becomes
empty. An inactive movie remains visible as unavailable, with removal still possible.
Loading, empty, retryable errors, session expiry, and incomplete profiles are handled.
No backend or schema changes are introduced.

`AddToList.jsx` is reused on provider and local Movie Details. It loads the current
user's lists only after Choose a list is clicked, supports selector pagination, and
links to management when no lists exist. Adding is an explicit action: if the movie
has no local ID, the existing TMDB resolve endpoint runs first. Only the returned
canonical string ID is sent as movie_id. Duplicate adds use AB#91's idempotent API,
so the success message does not claim that a duplicate was newly inserted.
Lists are never automatically created, and changing the selected list does not save.

`services/lists.js` adds detail/membership calls using shared Axios, cancellation,
and the existing CSRF flow. Writes and loads ignore results after leaving the page.
`MovieDetailNavigation.jsx` retains review and watchlist controls and hosts AddToList.
Existing isolated details tests mock the new child, while separate tests exercise it.

## Movie identity and navigation

Provider cards still navigate to `/movies/:tmdbId`. Local-only cards use
`/movies/local/:movieId`, which reads `/api/v1/catalog/movies/{movieId}` through
`getLocalMovieDetails`. Canonical IDs remain strings; a local ID is never used as a
TMDB ID. Both routes reuse the existing details presentation, reviews, status, and
list controls. Local genres/cast use fallback rendering keys when provider IDs are null.
Inactive list cards have no details link. Lists and their movie metadata are never
stored in browser persistent storage by these components.

## Automated checks

From frontend:

```powershell
npm run test:unit
npm run test:ui
npm run build
```

Focused UI tests cover owner/read-only views, list/movie paging, direct list links,
removal recovery, inactive cards, local details, missing/private/session errors,
loading/retry, stale responses, empty selectors, and duplicate-safe add feedback.
Service tests verify resolve-before-membership sequencing, CSRF, string IDs beyond
JavaScript's safe numeric range, rejected resolution identities, and local API URLs.

## Manual checks (all passed)

1. Run Laravel and Vite using localhost. Log in with a complete profile and create
   a list through My Lists if needed. Click its name: `/lists/ID` should show its
   metadata and the empty-list message.
2. Open a movie through Discover Movies. Existing details, review, and watchlist
   controls should remain available. Select Choose a list, choose your list, and
   click Add movie. Follow View list and verify the correct movie and count.
3. Add the same movie again. The list must still contain one entry. Add a different
   movie and check both cards open the correct provider details.
4. On an unmapped TMDB movie, use browser Network tools to check that loading its
   details does not resolve it. The explicit Add movie action should POST resolve,
   then POST a canonical movie_id string to the list membership endpoint.
5. As the owner, remove a movie from list details. Check the count decreases and
   the shared movies database row remains. Refresh to confirm the removal persisted.
6. Set the list public in My Lists. Copy its `/lists/ID` URL and open it as another
   signed-in account. Confirm cards are readable but Remove/Manage controls are absent.
   Switch it private as its owner, then refresh the other account: expect the generic
   unavailable message. The other account's My Lists should never include it.
7. Open the list URL logged out: expect login and return navigation after login.
   Check incomplete-profile guidance for adding/removing entries.
8. If you have more than 12 lists/movies, test pagination in both selector and detail
   page. Remove the last movie on the last page and confirm recovery to an available page.
9. If local-only catalog records exist, add one through the API and check its list
   card opens local details without any TMDB detail request. Deactivated entries
   should show unavailable details and remain removable by their owner.
10. Block a list load or membership request in Network tools. Check useful errors
    and retries without deleting displayed data on failed removal. Navigate away
    while a request is slow and confirm its late result does not replace another list.
11. Check mobile/desktop layouts and keyboard access for selection, pagination, links,
    and removal. No real browser/layout checks are implied by the automated suite.

List creation/editing/deletion remains AB#92. Full cross-feature verification is AB#94.
