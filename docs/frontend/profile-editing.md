# Profile editing (AB#36)

## Implementation

Select Edit profile to open a draft prefilled with name, email, bio, and
favorite genres. Cancel discards the draft. Save submits PATCH
/api/v1/me/profile through the shared Axios client after initializing CSRF.

Only editable fields and conditional current_password are submitted.
Blank bio becomes null; empty genres is []. Email is trimmed and
lowercased, while password whitespace is preserved. A changed normalized
email requires current-password confirmation. Password is never placed
in shared account state or browser storage.

Client validation checks required fields, lengths, and genre selection.
Laravel remains authoritative for RFC email syntax, uniqueness, password
confirmation, and authorization. Backend 422 errors appear under the
corresponding field; indexed genre errors appear under the selector.
401 follows the existing sign-out/redirect flow. 419 asks for resubmission
to refresh security state.

While saving, fields and Save/Cancel are disabled. Duplicate submissions
are guarded and requests are aborted on unmount. Aborting a request does
not guarantee that a server has not already saved it.

Success requires a valid saved profile in the PATCH response. The view
renders this returned profile, and shared account name/email are updated.
No separate GET is necessary because PATCH returns the saved profile.

Network errors retain non-password entries and show no success. Since
the server may have saved before the connection failed, feedback asks
the user to reload before retrying. Password confirmation clears on error.

Completion messaging remains AB#37; broader frontend verification is AB#38.

## Automated verification

From frontend:

    node --test tests/profileForm.test.js
    npm run lint
    npm run build

Node tests cover draft isolation, allowlisting, normalization, clearing
optional fields, email-change confirmation, password whitespace,
Unicode lengths, and genre validation. These are not browser UI tests.

## Browser checks before closing AB#36

Use a test account and disable old browser response overrides.

1. Verify prefilled fields; edit then Cancel and confirm nothing changes.
2. Save a name, bio, and genres; verify success, view, reload, and the
   updated name on home.
3. Clear bio and all genres; save/reload and verify placeholders.
4. Try blank name, invalid email, and overlong bio: verify field errors
   and retained entries without success.
5. Select five genres: additional choices disable; selected ones can
   still be removed.
6. Change email: verify current-password input. Wrong password shows a
   field error; correct password saves the email.
7. Try an existing account email: verify Laravel validation error and
   unchanged saved data.
8. Stop backend after entering edit mode, then Save: verify error and
   retained draft. Restart and reload to reconcile actual saved values.
9. Inspect saving feedback, mobile layout, and keyboard accessibility.
10. Confirm restricted fields are absent from controls and PATCH body.
    Do not share requests containing passwords.

No backend or development database changes are made by this task.
