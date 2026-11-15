# Movie review and moderation verification — AB#65

This report verifies the review API and Movie Details review flow, including first-review catalog resolution from AB#102. Backend tests use SQLite in memory. Browser checks use the local Laravel and React apps and two separate sessions with complete test accounts.

## Automated results

| Check | Command | Result |
| --- | --- | --- |
| Focused backend review regressions | `php artisan test --filter 'ReviewTest\|ReviewApiTest\|MovieDetailsReviewContextTest\|ResolveTmdbMovieTest\|PublicProfileTest'` | 26 passed, 1 skipped, 201 assertions |
| Moderation compatibility regressions | `php artisan test --filter 'ReviewModerationCompatibilityTest'` | 2 passed, 29 assertions |
| Full backend suite | `php artisan test` | 271 passed, 2 skipped, 1,972 assertions |
| Focused React review suites | `npm run test:ui -- tests/ui/movieReviewForm.test.jsx tests/ui/movieReviewsPanel.test.jsx` | 16 passed across 2 files |

Run the PHP commands from `backend` and the npm command from `frontend`. The SQLite suite skips the direct SQL constraint tests for review ratings and movie identity because those checks run on MySQL or MariaDB.

The compatibility tests verify that the public average and count follow visible review creation, edits, and deletion. They also verify that a hidden review blocks a duplicate submission, retains its moderation fields when edited by its owner, and remains excluded from the public list and statistics.

## Manual browser results

| Check | Observed result |
| --- | --- |
| Required rating | Submitting without a star showed “Choose a rating from 1 to 5.” No write requests were sent. |
| First review and catalog resolution | Account A submitted four stars without text. The resolver POST returned a local movie ID, the review POST used it, and the following GET showed one review card and a platform rating of 4 / 5. |
| Second account and ownership | Account B submitted two stars on the same movie. The form reused the catalog mapping, the review appeared after refetching, and the average became 3 / 5. Each account could manage only its own review. |
| Edit and network recovery | Saving while offline showed an error and preserved the draft. After reconnecting and retrying, the edit succeeded and the average became 3.5 / 5 across two ratings. |
| Hidden review | After setting Account B’s test review to `hidden` in Tinker, it disappeared from the visible list. Account B could still edit it. |
| Confirmed deletion | The delete button opened a confirmation prompt. Confirming sent DELETE and removed Account A’s review from the database. |
| Anonymous access | The signed-out React Movie Details page prompted for login. The first direct API request returned `404` for ID `299534`. Anonymous `200` verification is pending a rerun with the active movie’s canonical local ID. |

Moderation was simulated by updating a test review in the development database. The automated guest-listing and hidden-only tests passed.

See the [reviews API contract](../api/movie-reviews-contract.md) and [review storage contract](../architecture/review-storage.md).