# Task 99: profile privacy and follow verification

## Automated coverage

- `PublicProfileTest`: valid/missing profiles, exact public identity fields,
  sensitive/private activity omissions, bounded previews and relationship counts.
- `FollowTest`: follow/unfollow, duplicate requests and database uniqueness,
  self-follow rejection, unauthenticated writes, follower/following pagination,
  viewer-specific state on profiles and both lists, deletion cascades in both
  directions, preservation of unrelated relationships and rejected-write safety.
- `frontend/tests/ui/publicProfile.test.jsx`: guest access, login link, content
  links, follow/unfollow counts, self controls, failure/retry, missing profiles,
  disabled controls during pending writes and navigation between profiles.

Run from `backend`: `php artisan test --filter="FollowTest|PublicProfileTest"`.
Run from `frontend`: `npm run test:ui -- publicProfile`, `npm run build`.

## Verification results

- Public-profile UI tests: 8 passed.
- Frontend production build: passed.
- Frontend lint: completed with an existing Fast Refresh warning in AuthContext.
- Backend privacy/follow tests: 16 passed (135 assertions), verified on
  2026-11-17 in a temporary backend copy with SQLite. This bypassed the
  local PHP filesystem issue without changing project permissions.
- Required manual profile-navigation and follow-control checks: passed by Joy,
  using the local app and screenshots on 2026-11-17/18.

## Manual verification results

- Another user's public profile loads without displaying email or preferences.
- Follow/unfollow updates counts and persists after refresh (user confirmed).
- Joey's own public profile at `/users/3` hides the Follow button (screenshot).
- Missing user `/users/2` displays a readable not-found state (screenshot).
- Guest access and the login prompt work (user confirmed).
- Login returns the viewer to the requested public profile (user confirmed).
- Clicking a review author opens their public profile (user confirmed).
- Public review content renders correctly (screenshot).
- Movie-link navigation and narrow-window layout work (user confirmed).

Duplicate requests, self-follow rejection, unauthenticated writes, pagination,
viewer-specific state, deletion integrity, pending-write controls and error/retry
behavior are covered by the passing automated tests. Separate manual checks of
rapid clicks, a third viewer, populated list links, keyboard-only operation,
network failure and the browser console were not recorded.

## Status

Task 99's required automated tests and manual profile-navigation/follow-control
verification are complete. No critical UI issue was reported during the manual
checks. Changes remain local; this report does not indicate a commit, push or
work-item status change.
