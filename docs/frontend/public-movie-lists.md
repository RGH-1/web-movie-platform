# Public movie lists (AB#109)

Signed-in viewers can choose **Public movie lists** from the homepage. React uses
`GET /api/v1/lists/public?page=<n>` through the shared Axios client. The URL stores
the page number for browser Back and refresh. Each title opens the existing list
details page, and each author name opens a public profile.

Public profiles tell guests to sign in before opening a list. The protected route
remembers the destination for email/password login. For social sign-in, Laravel
accepts only movie-list destinations and stores the destination in the OAuth
session during sign-in. Laravel checks list access after sign-in.

## Verify locally

Start the configured Laravel API at `http://localhost:8000` and Vite at
`http://localhost:5173`. Use separate browser profiles for two accounts.

1. With account A (a complete profile), open **My Lists** at `/my-lists`. Create
   a public list named **Weekend favorites** with a description. Add a movie
   through **Add to list**. Create a private list and copy its details URL.
   Have at least 13 public lists in the test database to check pagination.
2. With account B, choose **Public movie lists** on the homepage. Find
   **Weekend favorites** and check its author, description, and **1 movie** label.
   Use **Next**, **Previous**, and the page field to change pages. Open a title,
   then use browser Back to confirm the same results page appears. Click the
   author to open their public profile.
3. Confirm the private list is absent. Open its copied URL as account B. The page
   should say **List not found or unavailable to your account**.
4. Signed out, visit `/users/<authorId>`. Check for the sign-in message above
   the list links. Click **Weekend favorites** and sign in as account B. The
   browser should return to the selected list. Repeat with each configured
   social provider using an account that can sign in with it.
5. Throttle the connection in your browser DevTools and reload discovery. Look
   for **Loading public lists…**. Block the discovery request and reload again.
   After the error appears, remove the block and select **Try again**.
6. With a signed-in account on a separate test database with zero public lists,
   open `/lists/public` and verify **No public movie lists yet** appears.

Discovery sends `Accept: application/json` and the existing Sanctum session
cookie, with no request body. For step 3, `GET /api/v1/lists/<privateId>`
with account B’s session should return HTTP 404.
