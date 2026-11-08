# Profile frontend verification (AB#38)

## Running tests

From frontend, install the locked development dependencies:

    npm ci

Then run:

    npm test
    npm run lint
    npm run build

npm test runs the existing Node logic/service tests and the new React UI
suite. Use npm run test:unit or npm run test:ui to run either separately.

Verified using Node 22.20.0. The Node suite needs
--experimental-test-module-mocks for the existing movieSearch.test.js.
That test uses Node's experimental module mocking, so its experimental
warning is expected. It does not indicate a failing test.

## New test setup

vitest.config.js uses jsdom and React Testing Library. The fixed local
test API URL is defined only in test configuration. No .env is required.

tests/ui/setup.js registers DOM assertions and cleans up rendered React
trees after each test.

tests/ui/profile.test.jsx renders the actual ProfilePage, edit form,
ProtectedRoute, AuthProvider, and completion notice. It calls the actual
Axios-backed profile service with a fake Axios adapter. No request reaches
Laravel, MySQL, or TMDB. No account, password, cookie, or browser override
from the developer's machine is used.

Minimal test home/login routes isolate profile behavior from unrelated
movie features. They do not replace or modify the production router.

## Coverage

- Authenticated profile retrieval uses the current-account endpoint,
  without forwarding client-selected account IDs.
- Plain-text bio rendering and optional-field placeholders.
- Guest redirects before private profile data is exposed.
- Loading, empty profile, retrieval failure, and retry.
- Prefilled editing and cancel without saving.
- Successful save updates the view and shared account name.
- Submitted payload includes only editable fields and requests CSRF
  initialization before PATCH.
- Client-invalid input does not send a save request.
- Laravel field errors, including indexed genre errors, appear in the UI.
- Failed updates retain non-password drafts, clear password confirmation,
  and do not display success.
- 419, server failure, network failure, and session expiration.
- Disabled controls and duplicate-submit protection while saving.
- Incomplete-profile guidance opens editing and clears after successful
  repair; the edit query parameter is removed.
- Explicit 403 PROFILE_INCOMPLETE updates the notice through the actual
  auth interceptor; unrelated 403 responses do not.
- Empty optional fields do not create a completion warning.

## Verification results

- 17 existing Node tests passed.
- 18 new React interaction tests passed.
- Production build passed.
- Lint reports only the existing AuthContext Fast Refresh warning.
- No production source changes or backend changes were necessary.

## Boundaries

jsdom tests exercise DOM interactions and mocked HTTP responses. They do
not verify Bootstrap layout at actual screen sizes, real browser cookie/
CORS enforcement, or backend ownership authorization. Use the previous
profile manual checklists for visual/live-session checks and the Laravel
profile test suites for actual backend permission and persistence checks.
The frontend payload tests show what the normal interface submits; the
backend must still reject crafted unauthorized requests.
