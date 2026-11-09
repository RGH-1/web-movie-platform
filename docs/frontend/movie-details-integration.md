# Movie details integration (AB#55)

## Existing implementation reused

The merged AB#53/AB#56 work already connected MovieDetailNavigation to
getMovieDetails, rendered MovieDetailsPage, and provided loading/error
states and a validated YouTube trailer link. AB#52 supplies the Laravel
endpoint. AB#55 completes the remaining integration safeguards rather
than creating a second route, page, or HTTP service.

## Changes

- getMovieDetails still calls GET /api/v1/movies/{id} using the shared
  credentialed Axios client. It rejects empty/unusable responses and
  movie IDs that do not match the requested ID. Missing/non-array genre
  and cast lists normalize to empty lists.
- Temporary failures provide a manual Try again action.
- HTTP 503 honors a positive integer Retry-After delay (30-second fallback)
  before enabling retry. No automatic retry is sent.
- 401 redirects to login, 403 provides Complete profile, and 404/422
  display not-found/invalid-link guidance without a retry action.
- Route changes show loading instead of the previous movie; responses
  from obsolete requests are ignored.
- The existing page displays principal cast/photos with fallbacks and
  opens valid YouTube trailers in a separate tab. Missing/unsupported
  trailer information uses the existing fallback.

No backend routes, authentication policies, TMDB credentials, or database
records are changed by this task.

## Automated verification

From frontend:

    npm test
    npm run lint
    npm run build

movieDetailsIntegration.test.jsx exercises the actual movie service and
route component with a fake Axios adapter. It covers service paths,
cast/trailer rendering, missing metadata, mismatched/empty payloads,
manual retry, Retry-After, ID changes and late responses, 401/403 actions,
and invalid IDs without network access.

Existing layout/navigation/trailer/profile/authentication tests remain
included. These simulated responses do not establish live TMDB access.

## Manual checks all passed

1. Start Laravel and Vite using matching 127.0.0.1 hostnames. Sign in.
2. Search Interstellar, open View Details, and confirm the URL, title,
   metadata, and cast match the Laravel response for that ID.
3. Open Watch Trailer, if available. Confirm YouTube opens the intended
   trailer and the details page stays open in the original tab.
4. Open a second movie and use browser back/forward; confirm the correct
   title and trailer are shown each time.
5. Reload a valid details URL to verify the signed-in session and data.
6. Try /movies/abc and /movies/0 for invalid-link guidance. A nonexistent
   but valid ID should show not-found when Laravel returns 404.
7. After authentication is restored, simulate a failed details GET:
   verify error/retry, then restore access and retry successfully.
8. Check a movie with no trailer/optional metadata or a temporary response
   override: verify fallbacks. Disable all overrides afterward.
9. Inspect mobile/desktop layout and keyboard access to retry/back/trailer.

For a real 503, retry becomes available after the backend delay; it does
not automatically fetch again. A new page load cannot retain a prior
in-memory delay. Use the automated fake-clock test for deterministic
rate-limit verification without sending repeated requests to TMDB.
