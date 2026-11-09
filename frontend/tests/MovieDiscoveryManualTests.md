
# AB#52 — Movie Search and Browsing Manual Tests

## Objective

Verify that the movie-search interface works correctly with the Laravel API, handles errors safely, supports pagination and movie navigation, and displays missing metadata appropriately.

## Test Environment

- Frontend: React + Vite
- Backend: Laravel REST API
- Movie data: TMDB
- Browser: Microsoft Edge
- Test method: Manual browser testing

## Test Results

| # | Test Scenario | Expected Result | Actual Result | Status |
|---|---|---|---|---|
| 1 | Search for "Harry Potter" | Multiple movie cards appear | 34 movies found; cards and posters displayed | PASS |
| 2 | Search for "xyzabc987654321" | No-results message appears | "No movies found. Try another search." | PASS |
| 3 | Submit an empty query | Validation message appears | "Please enter a movie title." | PASS |
| 4 | Navigate to page 2 | Different movies appear | Page 2 displayed different results | PASS |
| 5 | Click View Details | Navigate to selected movie | Correct movie ID appeared in URL | PASS |
| 6 | Stop Laravel backend | API failure handled without crashing | Error message displayed | PASS |
| 7 | Restart Laravel and retry | Search works again | Movie results loaded successfully | PASS |
| 8 | Enable network throttling | Loading indicator appears | Spinner and "Searching for movies..." displayed | PASS |
| 9 | Search for "!!!@@@###" | Invalid/unusual input handled safely | Zero results; no crash | PASS |
| 10 | Movie without poster | Placeholder displayed | "No poster available" displayed | PASS |
| 11 | Movie with missing poster | Available metadata still displayed | Title, release year, description, and button displayed | PASS |
| 12 | Return from Movie Details | Previous search and page restored | Search results and page 2 preserved after fix | PASS |

## Bug Discovered

### Search state lost after navigating back

**Problem:**
After clicking "View Details" and returning to Movie Discovery using the browser's Back button, the previous search results disappeared.

**Expected behavior:**
The search query, movie results, and current page should remain available.

**Fix:**
Updated `src/pages/MovieDiscovery.jsx` to save and restore discovery state using `sessionStorage`.

The saved state includes:
- Search input
- Submitted query
- Movie results
- Current page
- Total pages and results

**Verification:**
1. Searched for "Harry Potter".
2. Navigated to page 2.
3. Opened a movie's details page.
4. Pressed the browser's Back button.
5. Confirmed that the previous search results and page 2 were restored.

**Result:** PASS

## Build Verification

Command:

    npm run build

Result:

    Vite production build completed successfully.
    103 modules transformed.
    No compilation errors.

## Conclusion

All 12 documented manual test scenarios passed.

The movie-search interface correctly handles searches, empty results, validation, loading states, API failures, pagination, and movie-card navigation.

One navigation-state bug was discovered, fixed, and successfully retested.

Movie-details content remains a frontend placeholder and is outside the scope of this task.
