# My Lists management — AB#92

The protected `/my-lists` route displays only `/api/v1/me/lists` results. The home
page links to it for signed-in users. The backend remains the ownership authority.
Public lists still belong to their author; the page never fetches public discovery
or another account's management data.

`services/lists.js` uses the shared Axios client and refreshes Sanctum CSRF before
writes, following profile editing. String list IDs are preserved without numeric
conversion. No backend/schema changes are part of AB#92.

`MyListsPage.jsx` displays names, descriptions, public/private visibility, and movie
counts. A shared inline form creates/edits name, optional description, and visibility.
Lists start private. Blank names and name/description lengths are checked locally;
Laravel validation is also displayed without clearing the form. Cancel does not save.
Deletion requires inline confirmation and preserves catalog movies.

Requests show loading/error/success states; writes disable duplicate submissions.
Load failures have a Refresh lists action. Failed mutations preserve the form/list.
Requests are aborted when leaving the page. Expired sessions redirect to login.
Incomplete profiles can read lists, with writes disabled and profile guidance shown;
server-side PROFILE_INCOMPLETE responses also trigger guidance.

The existing MoviePagination control supports direct page jumps. After deletion,
an empty page beyond the last page is redirected to the last available page.
The grid and forms use Bootstrap responsive layouts and labeled native controls.

Agreed scope: list-details links and their destination page are added in AB#93.
There are no placeholder/broken details links in AB#92. Add-to-list and membership
management also remain in AB#93.

## Automated checks

From frontend:

```powershell
npm run test:unit
npm run test:ui
npm run build
```

`lists.test.js` checks service URLs, CSRF sequencing, pagination, visibility, and
string IDs. `myLists.test.jsx` checks CRUD, confirmation/cancellation, pagination
recovery, validation/errors, protected access, incomplete profiles, and cancellation.
Mocked checks do not establish browser layout or live cookie/database behavior.

## Manual checks(all passed)

1. Start Laravel and Vite using the project's localhost configuration, log in with
   a complete profile, and select My Lists on the home page or visit
   `http://localhost:5173/my-lists`.
2. Check existing lists show their names, descriptions, visibility, and movie counts.
   A new account should see the empty state. No other user's lists should appear.
3. Create a list with only a name. Check Private is selected by default and the new
   list appears. Refresh the browser to verify persistence.
4. Edit the list's name, description, and Public checkbox. Save, then refresh and
   reopen Edit to verify all values persisted. Cancel an edit and verify it did not save.
5. Submit a whitespace-only name or an overlong name/description. Check feedback and
   no saved change. Server validation errors should also retain entered values.
6. Select Delete, then Cancel: the list must remain. Confirm deletion afterward:
   the list must disappear. Verify catalog movies survive, including movies from
   the deleted list. Add/remove controls are not included yet.
7. With more than 12 lists, test arrows/direct page entry. Delete the last list on
   the last page and confirm the previous available page is shown.
8. Block `/api/v1/me/lists` in browser Network tools and refresh the page: check the
   load error. Unblock it and select Refresh lists. Block a mutation request and
   confirm the form or list remains available for retry.
9. Check at 375px width and desktop width, including long names/descriptions. Use
   Tab/Enter to create/edit and confirm/cancel deletion; verify no horizontal overflow.
10. Log out and open `/my-lists`: expect login. Log in as another account: expect
    only that account's lists. An incomplete-profile account should be able to read
    its own lists but see guidance and disabled write actions.

No new migrations are required for this frontend task; the AB#90/#91 database/API
must already be available locally.
