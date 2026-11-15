# List management and visibility verification — AB#94

## Scope

AB#94 adds cross-feature verification over AB#90–93 without changing application
behavior, routes, schema, dependencies, or environment files. Lists default private;
public lists are shared only with signed-in users. Duplicate names remain allowed;
duplicate membership in the same list is prevented.

## Automated coverage

| Area | Evidence |
| --- | --- |
| Schema/foreign keys | MovieListSchemaTest: real relationships, private defaults, uniqueness, required references, owner/list cascade, movie deletion restriction. |
| API boundaries | MovieListApiTest: login, complete-profile writes, ownership, private/public access, paging, validation, canonical IDs, duplicate adds, inactive removal. |
| Visibility lifecycle | MovieListWorkflowTest: private to public to private revokes both direct access and discovery, without changing ownership or membership. |
| Deletion isolation | MovieListWorkflowTest: deleting one list preserves the same catalog movie and another owner's list membership. |
| Failed writes | MovieListWorkflowTest: invalid edits and memberships leave metadata, ownership, visibility, and membership unchanged; explicit null clears description. |
| Service identity | lists.test.js and listMembership.test.js: URLs, CSRF, canonical string IDs, explicit resolution, invalid resolution rejection. |
| UI components | myLists.test.jsx and listDetails.test.jsx: CRUD/errors, confirmation, route protection, owner/read-only controls, inactive/local cards, late-response guards, pagination. |
| Connected UI workflow | listWorkflow.test.jsx: real list services and components navigate create/edit, resolve/add twice, view/remove, cancel/confirm deletion. Only Axios HTTP transport is mocked; this is not a live Laravel browser test. |

The Laravel tests execute real application routes/models/database operations; external
TMDB calls are not needed for these list tests. They do not establish actual browser
cookies/CSRF behavior. The isolated MySQL run verifies database compatibility, but is
not a concurrent-writer stress test. The HTTP UI fixture is not evidence of server
privacy enforcement; the real Laravel tests establish that separately.

## Commands

From backend:

```powershell
php artisan test --filter=MovieList
php artisan test
```

From frontend:

```powershell
npm run test:unit
npm run test:ui
npm run build
```

Default Laravel tests use SQLite in memory. For MySQL testing, use a dedicated,
throwaway database only: RefreshDatabase may reset its schema. Never point those
tests at the working development database.

## Automated results

- Full backend suite: 272 passed, 2 existing MySQL-only checks skipped on SQLite.
- All 20 list tests on isolated MySQL: passed, 176 assertions.
- Frontend unit suite: 30 passed.
- Frontend UI suite: 180 passed across 19 files.
- Production build, new test-file formatting/lint, and diff checks passed.
- The temporary MySQL test database and verification helper were removed.
- No development database migrations or data changes were performed by these checks.

## Manual end-to-end checklist — all verified

Use accounts A and B, both with complete profiles. Run the app with the established
localhost setup. Use a temporary list such as `AB94 check` and clean it up afterward.
Do not delete a real account or alter an existing movie's lifecycle for this check.

1. As A, open `/my-lists`. Create `AB94 check` with a description, leaving visibility
   private. Refresh: verify values persist. Cancel an edit and confirm nothing saved.
2. Submit a whitespace-only name and an overlong description. Verify useful validation
   and unchanged stored metadata. Edit to valid values and save successfully.
3. Open a movie through discovery. Check the existing review/watchlist controls still
   display. Choose `AB94 check` in Add to List and add it twice. Follow View list:
   verify one movie entry and count 1. The movie details link must open the right film.
4. Add another movie; verify two entries. Remove one from list details, refresh, and
   verify its entry is gone. Verify the catalog movie still exists in the database.
5. Copy the list's `/lists/ID` URL. As B in a separate browser/profile, open it while
   private: expect the generic unavailable message. B's My Lists must exclude it.
6. As A, mark the list public. As B, refresh its direct URL: verify metadata/cards are
   visible but Remove/Manage controls are absent. B's My Lists must still exclude it.
   If checking public discovery in Postman, use B's authenticated session and
   `GET /api/v1/lists/public`; confirm the list appears there.
7. As A, switch it back to private. As B, refresh the direct URL and public-discovery
   request: expect denied direct access and no list in discovery.
8. As A, cancel list deletion first: the list must remain. Confirm deletion afterward:
   its details URL should become unavailable, while its catalog movies remain.
9. Log out and open the saved list URL: expect login. Check that authentication works
   after logging in again. Incomplete-profile write rejection is covered automatically;
   test a dedicated incomplete account manually only if one is available.
10. If more than 12 test lists/movies exist, exercise selector/detail pagination and
    direct page jumps. Existing automated tests cover deletion of the last item on
    the last page without requiring many manual fixtures.
11. Check at mobile width and desktop width. Use keyboard Tab/Enter for list forms,
    selectors, links, and confirmation/removal. Verify no horizontal overflow.
12. Block a list GET or membership write in browser Network tools. Verify useful
    errors, retained data after a failed write, and successful retry after unblocking.


