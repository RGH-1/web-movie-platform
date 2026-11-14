# Popular and Upcoming sections — AB#74

The existing protected `/movies` page now has Title search, Popular, and Upcoming category buttons.
Search remains the initial view. Its input, results, and pagination are retained while browsing feeds.
Each feed starts at page 1 when selected; returning from details opens the existing discovery/search view.

`MovieFeed.jsx` loads one category through the shared movie service/Axios client, reuses MovieCard,
and provides loading, empty, error/retry, and compact pagination states.
`MoviePagination.jsx` is shared by search and both feeds: arrow buttons, an editable page number,
and the current/total pages. Press Enter to jump; blank, fractional, and out-of-range pages are rejected. Late responses after leaving
or changing pages are ignored. The grid uses the existing Bootstrap breakpoints.
Provider IDs still drive `/movies/:tmdbId` links; canonical local IDs are not substituted.
No backend, authentication, or catalog behavior changes. See the AB#73 API contract.

## Automated checks

From frontend, run `npm run test:unit`, `npm run test:ui`, `npm run lint`, and `npm run build`.
Feed tests cover both endpoints/pages, provider links, loading/empty states, old responses,
network/upstream failures and retry, and preserving search across category switches.

## Manual checks (run separately)

1. Start the configured Laravel backend (`php artisan serve` from backend) and React
   frontend (`npm run dev` from frontend). Use the project's configured database and TMDB credentials.
2. Log in with a complete profile and open Discover Movies. Search for a title and go to page 2.
3. Select Popular. Type 45 (or another valid page) into the page field and press Enter;
   confirm it loads directly. Test blank, 0, fractional, and above-maximum values: no request should occur.
   Check that the boundary arrows are disabled at the first/last page. Repeat for Upcoming and search.
   Select Popular. Check cards and Previous/Next, then select Upcoming. It should start on page 1.
4. Select Title search. Confirm the earlier title/results/page remain and search pagination still works.
5. Open a feed card's View Details. Confirm the correct movie opens and existing review controls work.
6. In browser Network tools, confirm feed requests use Laravel `/api/v1/movies/popular` or
   `/upcoming` with the right page. Poster images can load from TMDB; API requests must go through Laravel.
7. Throttle the browser network and switch categories quickly. Confirm only the selected category
   appears. Block a feed request, confirm the error, unblock it, and select Try again.
   Empty responses and 503 responses are covered by automated mocks; inspect them manually only
   with a local request-override tool if available, without changing production/backend behavior.
8. Test at 375px and desktop widths. Check no horizontal overflow, stacked/responsive cards,
   visible category/pagination buttons, and keyboard Tab/Enter navigation.

Manual checks and live TMDB behavior are not established by mocked automated tests.
