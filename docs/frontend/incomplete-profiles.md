# Incomplete profile guidance (AB#37)

## Behavior

AuthProvider stores profileCompletion separately from authentication.
It reads meta.profile_complete and meta.incomplete_fields from Laravel's
GET/PATCH /api/v1/me/profile responses. After sign-in or account creation
it requests the profile status for that account. Profile updates replace
the status immediately from the saved response.

An Axios response interceptor also recognizes exactly HTTP 403 with
code PROFILE_INCOMPLETE. It records the backend field list while still
rejecting the original request, so the feature retains its own failure
handling. Other 403, 401, validation, and network errors are not relabeled
as incomplete profiles. Sign-out/401 clears completion state.

A reusable notice appears on home and the profile page when the backend
explicitly reports incompleteness. It labels missing/invalid name/email
and provides a Complete profile action. Bio and genres remain optional.
The action opens /profile?edit=1; cancel/save removes the edit parameter.
After a successful repair, fresh response metadata removes the notice.

No frontend middleware recalculates completeness or grants access to a
denied feature. No global completion redirect blocks unrelated pages.
Profile viewing/editing, authentication, health, and diagnostics remain
accessible under their existing rules. The backend continues to enforce
completion on selected feature routes such as movie search.

Unknown/unavailable metadata is not treated as proof of incompleteness.
Normal new accounts already have valid required registration fields.

## Checks

From frontend:

    node --test tests/*.test.js
    npm run lint
    npm run build

The completion tests check metadata interpretation, explicit denial
recognition, unrelated errors, optional-field independence, and labels.
Form tests from AB#36 remain included.

## Browser verification (all passed)

1. With a normal test account and no bio/genres, verify no completion
   warning on home/profile. Existing navigation and diagnostics work.
2. To preview incompleteness safely, use a temporary Edge response
   override for GET /api/v1/me/profile. Keep the real data object and
   change only meta to:
   {"profile_complete":false,"incomplete_fields":["name"]}
   Navigate home/profile without refreshing auth and verify the notice.
   This simulates server metadata; it does not change database values.
3. Select Complete profile and verify the existing edit form opens.
   Cancel must still allow profile viewing and navigation.
4. Disable the override before saving. Save valid profile information:
   the actual PATCH metadata should remove the notice. Verify cancel
   and save remove ?edit=1 so edit mode does not reopen unexpectedly.
5. An explicit 403 PROFILE_INCOMPLETE from any shared-client request
   should update the notice; other 403 responses must not. This is also
   covered by the parser tests; real production-route end-to-end
   verification belongs alongside the relevant feature.
6. Sign out: no stale completion notice appears for guests or the next
   signed-in account.
7. Verify narrow screens and keyboard activation of Complete profile.

## Verification results

- All 14 frontend logic tests passed.
- Production build passed.
- Lint reports the existing AuthContext Fast Refresh warning.
- Manually verified normal profiles, simulated incomplete-profile notices,
  opening editing from home/profile, cancelling, successful save recovery,
  logout state clearing, responsive layout, and keyboard navigation.
- Temporary browser overrides were disabled after testing.

The specific 403 response interpretation is covered by automated tests.
End-to-end browser verification through the production movie feature
remains pending its frontend integration.

Disable browser overrides and restore normal Network throttling after
manual checks. No development database changes are needed.
