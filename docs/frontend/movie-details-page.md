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

No route, API service, loading/error fetch states, or trailer playback
is added by this task. AB#53 owns the route/API function, and AB#55
connects live data and trailer playback. The existing
MovieDetailNavigation placeholder and authentication are unchanged.

## Development preview

Start the existing Vite server from frontend:

    npm run dev

Open:

    http://127.0.0.1:5173/movie-details-preview.html

The banner explicitly identifies sample data. Use Full sample and
Missing metadata to inspect both layouts. The preview has no account
or API access. Only sample images load from TMDB's image host; failures
show placeholders.

movie-details-preview.html and src/dev/movieDetailsPreview.jsx provide an
isolated Vite development entry. They do not modify App.jsx or the
production routing, and are not entries in the normal production build.
Remove this preview when live route integration replaces it in AB#55.

The sample response is in tests/fixtures/movieDetails.js and follows the
agreed response shape. Its values are illustrative, not live API output.

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

## Visual checks all passed

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
