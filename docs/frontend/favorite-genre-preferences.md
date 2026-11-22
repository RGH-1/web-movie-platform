# Favorite genre preferences — AB#115

## Behavior

The authenticated profile editor supports up to five favorite movie genres.

Preferences use the existing nullable users.favorite_genres JSON column.
Stored and API values are supported TMDB integer IDs:

```json
{
  "favorite_genres": [878, 9648, 10751]
}
```

GET/PATCH /api/v1/me/profile always selects the authenticated account.
The existing protected-field allowlist, authentication, and browser CSRF
requirements remain in force.

PATCH omission preserves preferences. An empty array clears them.
Database null is returned as an empty array.

Null input, numeric strings, unsupported IDs, duplicate IDs, non-list
values, and more than five selections return 422 without partial updates.

App\Support\MovieGenres defines supported IDs. The frontend mirrors the
mapping for readable labels and checkbox values. Update both mappings
together when intentionally changing supported genres.

Profile reads and saves do not depend on live TMDB availability.
The discovery genre endpoint remains unchanged.

## Migration

2026_11_22_154049_normalize_favorite_genre_to_tmdb_ids converts existing
supported slugs to integer IDs in place.

It preserves selection order, empty lists, database null, and updated_at.
Already-converted IDs are accepted. Invalid stored preferences abort
transactionally instead of being discarded.

The migration contains a frozen historical mapping. Do not change that
mapping when adding future application genres.

Apply the migration, backend contract, and frontend together.

## Recommendations

The existing recommendation service reads saved preferences and normalizes
IDs to internal genre slugs before candidate selection and scoring.

Signal weights and explanation contracts remain unchanged.
genre_preference explanations continue using normalized slug parameters.

Clearing preferences disables the explicit genre signal. Watched history,
ratings, people, similarity, and requested weather may still personalize
the result.

FavoriteGenrePreferencesTest uses two equally rated local candidates,
saves preferences through the profile API, and verifies that changing the
selection reverses final ranking.

## Automated verification

From backend:

```powershell
php artisan test --filter='FavoriteGenrePreferencesTest|ProfileTest|ProfileOwnershipTest|ProfileCompletionTest|RecommendationApiTest|RecommendationScorerTest'
php artisan test
```

From frontend:

```powershell
node --test tests/profileForm.test.js
npm run test:ui -- tests/ui/profile.test.jsx
npm test
npm run lint
npm run build
```

## Verification record

| Check | Result |
| --- | --- |
| Focused backend tests | Pending |
| Full backend suite and skip reasons | Pending |
| Focused frontend tests | Pending |
| Full frontend suite | Pending |
| Lint and production build | Pending |
| Existing preference migration | Pending |
| Browser save, refresh, and clear | Pending |
| Five-selection limit and keyboard/mobile use | Pending |
| Two-account ownership | Pending |
| Invalid input and retained failure drafts | Pending |
| Recommendation signals after save/clear | Pending |

Record the tested commit, actual outcomes, and any skipped checks before
closing AB#115.