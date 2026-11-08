# Profile viewing page (AB#35)

## Implementation

The /profile route is a child of the existing ProtectedRoute. It waits
for authentication restoration and redirects guests to /login, preserving
the attempted location. Authenticated users can follow My profile from
the home page and return using Back to home.

ProfilePage retrieves the current account through GET /api/v1/me/profile.
The shared Axios client supplies session credentials; AuthProvider clears
stale authentication on 401. The page also redirects on 401. No user ID
from the URL is used to select an account.

The Bootstrap layout displays name, email, optional bio, and favorite
genres. Optional fields have explicit placeholders. Bio is rendered as
plain text, not HTML. Loading, empty response, error, and manual retry
states are included. Requests are aborted on unmount.

The profile route deliberately does not require profile.complete:
incomplete users must retain access to their account page. Editing and
completion messaging remain AB#36 and AB#37.

## Local verification

Follow development-setup.md and auth-contract.md for database, session,
CORS, and matching host configuration. Run migrations if needed, start
Laravel from backend with php artisan serve, and start Vite from frontend
with npm run dev. Use http://127.0.0.1:5173 consistently.

Build checks from frontend:

    npm run lint
    npm run build

Browser acceptance checks (record results before closing AB#35):

1. Signed out, open /profile directly: expect the login page.
2. Sign in and follow My profile. Confirm name/email match Laravel's
   GET /api/v1/me/profile response rather than hardcoded content.
3. Refresh /profile: wait for authentication restoration and display
   the same account.
4. Confirm missing bio/genres show placeholders.
5. Throttle the request: observe loading feedback.
6. Simulate a 500 or network failure after auth restoration: observe
   the error, restore access, and use Try again.
7. Mock a 200 response with data: null: observe the empty state.
8. Expire the session and reload/request the profile: redirect to login.
9. Inspect mobile and desktop widths, including long email/bio content.
10. Navigate away while loading: the cancelled request must not replace
    the new page.

Automated lint/build checks do not replace browser session, layout, and
failure-state verification.
