# Public activity privacy — AB#106

## Settings

Authenticated GET/PATCH /api/v1/me/profile exposes:

| Field | Meaning |
| --- | --- |
| share_activity | Master activity-sharing switch |
| share_ratings | Share rating-only activity |
| share_reviews | Share written reviews, including their star ratings |
| share_public_lists | Share activity for lists already marked public |

All values are JSON booleans. PATCH omission preserves the current value.
Null, strings, integers, and arrays are rejected with 422.

The authenticated account is always the update target. Body identifiers
and protected/unknown fields are rejected. Existing Sanctum session and
CSRF requirements remain unchanged.

Example opt-in:

```json
{
  "share_activity": true,
  "share_ratings": false,
  "share_reviews": true,
  "share_public_lists": false
}
```

## Defaults and migration

New accounts default to false for all four fields.

The migration preserves existing share_activity values and adds all three
category fields as false. Existing accounts must explicitly select
categories before their activity appears through the expanded behavior.

Turning off the master switch preserves category selections.

No reviews, ratings, lists, or list memberships are deleted.

## Visibility rules

A category requires both its category switch and the master switch.

Rating-only activity has null or trimmed-empty review text. Written-review
activity contains text and includes its associated star rating. Written
reviews do not fall back into rating-only activity when review sharing is off.

The same model scopes filter public-profile previews/counts and the
following feed before limits and pagination.

Existing rules still apply:

- Feed authors must be followed by the viewer.
- Hidden reviews remain excluded.
- Reviews on inactive movies remain excluded.
- Private lists remain excluded.
- Owners and administrators receive no public-profile privacy bypass.

Public-profile reviews_count remains the combined count of eligible
rating-only and written-review records. Counts include all eligible
records, while previews remain limited to ten.

Preferences and excluded activity counts are not exposed publicly.
Profile/feed responses use private, no-store caching.

Changes apply to subsequent requests and to existing as well as future
activity. Previously downloaded content cannot be recalled.

## Scope

These settings control promotion on public profiles and followers' feeds.

Movie-page reviews and their community aggregates remain available under
existing moderation rules. Lists explicitly marked public retain their
existing visibility and public discovery behavior. Private-list
authorization remains unchanged.

Personal recommendations continue using the requesting user's own data.
Profile completeness does not require enabling sharing.

## Frontend

The existing profile editor contains the master switch and category controls.
Categories are disabled while the master is off, without clearing selections.

Only changed preference fields are submitted. Existing saving protection,
validation feedback, retained drafts, and CSRF handling are reused.

## Verification

From backend:

```powershell
php artisan test --filter='PublicActivityPrivacyTest|FollowingFeedTest|PublicProfileTest|ProfileTest|ProfileOwnershipTest|ProfileCompletionTest|AdminReviewModerationTest'
php artisan test
```

From frontend:

```powershell
npm run test:ui -- tests/ui/profile.test.jsx tests/ui/publicProfile.test.jsx tests/ui/followingFeed.test.jsx
npm test
npm run lint
npm run build
```

Tested commit: Pending.

| Check | Result |
| --- | --- |
| Migration and application/database defaults | Pending |
| Owner-only preference updates and strict validation | Pending |
| Category combinations in feed and public profiles | Pending |
| Pagination, preview limits, and counts | Pending |
| Master switch and content preservation | Pending |
| Forged-query and privileged-viewer checks | Pending |
| Frontend persistence/errors/saving states | Pending |
| Full backend suite and skip reasons | Pending |
| Full frontend suite | Pending |
| Lint and production build | Pending |
| Two-session and anonymous browser verification | Pending |

Record actual outcomes before closing AB#106.