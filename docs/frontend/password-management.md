# Password management UI — AB#77

## Entry points and API

- Login > Forgot password? opens public `/forgot-password`.
- Social-only password settings link to `/forgot-password?setup=1`, labeled Set up an
  app password / Send setup link. This changes presentation only, not API access or behavior.
- Both emailed flows use Choose your app password / Save app password on the final form.
- Emailed links open public `/reset-password?token=...&email=...`.
- Profile > Password settings opens protected `/account/password`.

`PasswordPage.jsx` uses a mode-keyed form so navigation between flows clears its
state. `services/passwords.js` uses the shared Axios client, credentials, cancellation,
and a fresh Sanctum CSRF cookie before each password POST, including guest recovery.
The existing AB#76 API performs token verification, password hashing, and database
session revocation. No new password endpoint, database migration, or dependency is added.

The current-user profile GET/PATCH response now includes the read-only boolean
`has_local_password`. It reveals no hash, password, provider token, or other user's
state. Password settings fetch that authenticated profile before choosing the form.
It is not an editable profile field. The existing profile test contract is updated.

## Local and social accounts

Accounts with a local password see Change password and must enter their current
movie-platform password. Accounts without one see email setup guidance instead;
they are never asked to enter a Google, Facebook, or GitHub password.
The existing recovery API can establish a local app password after mailbox proof,
while retaining linked social identities. This does not change the provider password.

If a social-only account has no email (supported for Facebook), password settings
explain that email recovery is unavailable and social login remains the route in.
Current profile APIs require a current password to change email, so that account
cannot self-serve email setup through this task. This existing account-policy issue
requires a separate backend decision; AB#77 does not bypass the requirement or alter
social account linking. Do not enter the provider password in app forms.

## Validation, errors, and success

New passwords mirror the installed Laravel rules: at least 12 characters, Unicode
uppercase/lowercase, a number and symbol, confirmed, at most 72 UTF-8 bytes. NUL is
rejected locally; password spaces are preserved. Laravel remains authoritative.
Inputs have autocomplete hints, labels and associated validation errors. Loading
and submitting states disable duplicate actions. Requests are cancelled when leaving.

Forgot-password success always shows the same account-neutral message. Email case
and surrounding whitespace are normalized before the request. Wrong current passwords
and field validation are displayed without raw response logging. Password inputs clear
after every submitted request, including failures. 419 offers retry with refreshed
CSRF; 429 disables the form until Retry-After elapses (safe 60-second fallback).
Authenticated change-session expiry redirects to login.

Reset URLs must contain exactly one nonempty token and valid email; malformed,
ambiguous, missing, expired and reused links show recovery guidance. The email is
read-only. The token is held in component memory and never rendered or stored in
local/session storage, logs, or navigation state. The query is removed from the
visible URL using replacement navigation after capture. Refreshing that cleaned URL
requires reopening the original email link. Production infrastructure must also avoid
logging incoming reset URLs or attaching analytics to reset forms.

After change/reset success, local authentication is cleared and login shows a fixed
success message. Backend policy invalidates the requesting session, all database
sessions for that account, and remember-me tokens. Social login remains attached.
This relies on the backend's documented SESSION_DRIVER=database policy.

## Automated verification

From backend: `php artisan test`.
From frontend: `npm run test:unit`, `npm run test:ui`, `npm run build`.

Profile tests cover the safe read-only capability flag. Password service/unit tests
cover CSRF, exact payloads, cancellation, Unicode/byte/confirmation rules. UI tests
cover local/social/no-email guidance, generic recovery, URL cleanup, invalid/expired
links, success navigation, validation, CSRF failure and rate-limit retry. Existing
AB#76/OAuth tests still verify hashing, mail rendering, token expiry/reuse, revocation,
and establishing a password for a social-only account. Mocked UI tests do not prove
SMTP delivery or browser-cookie behavior.

## Manual checks — All Passed

1. Run Laravel/Vite on localhost and log in using a test email/password account.
   Profile > Password settings must show the current app password field.
2. Try a wrong current password, weak password, and mismatched confirmation. Confirm
   field errors and no saved change. A failed request should clear password fields.
3. Submit a valid new password. Expect login with a success message. Confirm the old
   password fails and the new one works. Use a disposable test account or retain the
   chosen password securely; this changes real development credentials.
4. From login, choose Forgot password and request a link for the test account.
   Confirm the same generic feedback for an unknown address. Do not infer mail
   delivery from that feedback; provider throttling and mail outages are intentionally
   account-neutral. The app must not display a token.
5. Use Mailpit per docs/api/passwords.md: from backend run
   `docker compose -f compose.mail.yaml up -d` if needed. Configure the documented
   local SMTP settings and FRONTEND_RESET_PASSWORD_URL in your existing .env, then
   `php artisan config:clear`. Inspect mail at http://127.0.0.1:8025. Never switch to
   the log mailer for reset emails, and never share the reset link/token.
6. Open the emailed localhost link. Confirm the correct email, new-password form,
   and cleaned address bar. Submit a valid password, verify login success, then reopen
   the original link and submit again: expect invalid/expired guidance. A missing-token
   URL must also show that guidance without a reset request.
7. Log in via a configured Google/Facebook/GitHub account with no local password.
   Password settings must show setup guidance. If it has email, request/reset through
   that mailbox, then verify both local-password and social login still work. With a
   no-email Facebook account, verify the limitation message and no password form.
8. If another browser holds a session for the same account using database sessions,
   change/reset the password and verify that session is rejected on its next API call.
9. Check the form at mobile/desktop widths and use Tab/Enter.

Manual results remain separate from automated checks. No real credentials, tokens,
reset mail, or development user passwords were changed by automated verification.
