# Movie filtering and sorting interface — AB#101

## Scope and existing integration

AB#100 already delivered the filtering API, movie service functions, and an initial
MovieFilters component. AB#101 completes that UI instead of creating a second one.
The API, identity handling, existing movie cards, and protected discovery route remain
unchanged. Genres are read from Laravel; React contains no genre-ID list. The existing
service omits empty optional parameters and never calls TMDB directly.

Discover Movies > Title search > Browse by filters shows the filter form. Title
search and filtered discovery remain separate modes, with Popular and Upcoming
still accessible. Switching away unmounts filtered discovery; reopening it starts
with defaults. Title-search session state retains its existing behavior.

## Controls and feedback

- Genre shows provider names and All genres.
- A blank release year means Any year. Four-digit years from 1874 through the current
  year + 5 are valid, matching Laravel. Invalid values show field errors before a request.
- A blank minimum rating means Any rating. Numeric values from 0 to 10 are accepted,
  including decimals. Native suggestions offer 5+, 6+, 7+, 8+, and 9+ where supported.
  Suggestions are optional; direct input works in browsers without number datalists.
  Help text distinguishes TMDB ratings out of 10 from platform ratings out of 5.
- All six existing sorts remain supported. Apply filters submits drafts at page 1.
- Applied filters display the submitted genre name, year, rating, and sort. Draft
  edits do not relabel existing results or change pagination requests.
- Clear filters resets controls, submitted values, sorting, and page to defaults.
- The existing MoviePagination control supplies arrows and direct page entry.
  Page requests preserve submitted filters and sorting.

Existing loading, empty, backend validation, retry, failed-genre recovery, and late
response guards are retained. Genre failure leaves the other controls usable.
The form/grid use Bootstrap breakpoints and labeled keyboard-accessible controls;
backend and local errors are associated with their inputs.

## Automated checks

From frontend:

```powershell
npm run test:unit
npm run test:ui
npm run build
```

movieFilters.test.jsx covers independent/combined filters, submitted-versus-draft
state, all six sort requests, year/rating validation, direct page jumps, clearing,
provider genre recovery, empty/error states, details links, and switching modes
while an old filtered response is pending. Existing movieSearch.test.js verifies
Laravel parameter forwarding/empty omission; feed/search/details tests remain in
place. These mocks verify requests and UI behavior, not TMDB's actual ordering.

## Manual checks — Successfully passed

1. Start Laravel and Vite with the existing localhost configuration, log in, open
   `/movies`, then select Browse by filters. Confirm genres have readable names.
2. Apply genre alone, year alone, and rating alone, then a combination. Check
   Laravel requests and the Applied filters summary match the selected values.
3. Exercise all six sorts. Check each request's sort_by and compare live result
   ordering when metadata allows. Every Apply should start on page 1.
4. Edit an input without applying; check Applied filters remains unchanged. Use
   Next or a direct page jump and confirm the request retains the submitted filters.
5. Click Clear filters. Check All genres/Any year/Any rating/default popularity,
   page 1, and default results. Apply a decimal rating such as 7.5 as well.
6. Submit a fractional/out-of-range year and ratings outside 0–10. Check errors
   and confirm no discovery request occurs. Clear the fields and retry successfully.
7. Open details from filtered results and confirm the correct movie. Exercise title
   search, Popular, Upcoming, and filtered discovery without mixed result sets.
8. Throttle network requests, switch to a feed before discovery completes, and check
   the late response does not replace that feed. Block genres: other filters should
   remain usable. Unblock and Retry genres to recover.
9. Block discovery, check its error/retry, unblock and retry. Automated mocks cover
   empty results and backend 422/502/503 feedback without changing backend behavior.
10. Test at 375px width and desktop width. Check controls stack without horizontal
    overflow, the movie grid adapts, labels/help remain readable, and Tab/Enter works.

No new migrations or dependencies are needed. The visual/layout and live backend
checks above are separate from automated test execution.
