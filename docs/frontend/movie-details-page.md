# Movie details layout (AB#54)

## Scope

MovieDetailsPage accepts one movie object matching data in
docs/api/movie-details-contract.md. It presents title, poster, backdrop,
synopsis, release date, runtime, genres, TMDB rating, director, principal
cast, and a dedicated trailer section.

Bootstrap supplies responsive rows/columns. Images keep a fixed aspect
ratio; absent or failed image URLs display text placeholders. Optional
metadata uses explicit fallbacks. A zero TMDB rating is displayed as
0 / 10 rather than treated as missing. Synopsis is rendered as plain text.

MovieImage is kept inside the page because it is currently used only
there, for poster, backdrop, and cast photos.

AB#54 introduced the presentation layout. Live route/API integration and
trailer behavior are provided by the merged implementation and AB#55.

## Viewing the integrated page

Start Laravel and Vite, sign in with a complete profile, then search for a
movie and select View Details. A direct example is:

    http://127.0.0.1:5173/movies/157336

The temporary sample-data HTML/React preview was removed in AB#55 after
the live route became available. The route now uses Laravel responses;
it does not import sample fixtures.

The sample response remains in tests/fixtures/movieDetails.js because
the automated layout, navigation, and integration tests still use it.
Its values are illustrative, not live API output.

See [movie-details-integration.md](movie-details-integration.md) for live
integration behavior and verification steps.
## Automated verification

From frontend:

    npm test
    npm run lint
    npm run build

Movie-details component tests cover all required sections, absent optional
metadata, failed images, zero rating, plain-text rendering, and changed
image sources. Existing profile and movie-search tests remain included.

Automated checks passed during implementation. Lint retains the existing
AuthContext Fast Refresh warning.

## Historical AB#54 sample-preview checks (passed)

These checks were completed before removal of the temporary preview.
Use the live route for subsequent browser checks.

1. Open the full sample preview at desktop width; verify poster/metadata
   alignment, backdrop sizing, cast cards, and trailer section.
2. Inspect at 375px and 768px widths. Content should stack/wrap without
   horizontal scrolling or clipped text.
3. Open Missing metadata and verify all fallbacks remain readable.
4. Verify image-load failures do not collapse the layout.
5. Inspect long titles/synopsis and keyboard-readable section headings.

Contract note: its introductory authentication requirement conflicts with
an older paragraph saying the endpoint is public. This layout does not
change access rules; resolve that wording with the API owner when doing
route/data integration.
