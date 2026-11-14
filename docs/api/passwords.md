# Password changes and recovery (task 76)

All endpoints accept JSON under `/api/v1/auth`. Send `Accept: application/json`.
For the first-party SPA, use the existing Sanctum cookie/CSRF flow: fetch
`/sanctum/csrf-cookie`, include credentials and send `X-XSRF-TOKEN` on POSTs.
Recovery does not require login; stateful browser requests still require CSRF.

| POST endpoint | JSON fields | Success |
| --- | --- | --- |
| `/change-password` | `current_password`, `password`, `password_confirmation` | 200; sign in again |
| `/forgot-password` | `email` | 200 with the same generic message for known, unknown, and broker-throttled addresses |
| `/reset-password` | `email`, `token`, `password`, `password_confirmation` | 200; sign in again |

New passwords require at least 12 characters, upper and lowercase letters,
a number and a symbol, matching confirmation, and at most 72 UTF-8 bytes
(bcrypt's input limit). Password values are not trimmed. Validation returns 422
with field errors; unauthenticated changes return 401. Invalid, expired, reused,
and unknown-account reset links all return the same 422 token error. Each IP
has a shared limit of five password requests per minute (429 on excess).
Laravel's database password broker additionally limits email issuance to one
per account per 60 seconds. Reset tokens are hashed in storage, expire after
60 minutes, and are deleted after success. Account row locks serialize resets
and changes so concurrent requests cannot reuse a token on MySQL.

## Sessions and social accounts

Use `SESSION_DRIVER=database` (the project default) in deployed environments:
successful changes and resets delete **all** of the account's database sessions,
including the current session, and rotate the remember-me token. The requesting
browser session is invalidated and its CSRF token regenerated. Password changes
also delete outstanding reset links. Other users' sessions are untouched.
Non-database session drivers do not support this bulk deletion policy and must
not be used in production without an equivalent revocation mechanism.

Authentication currently supports cookie sessions, not personal access tokens
(the User model does not issue Sanctum bearer tokens). If token authentication
is introduced, revoke those tokens in the password-update transaction too.

The new migration allows `users.password` to be null for social-only accounts.
Those accounts cannot use change-password until they have a local password;
they must first prove mailbox ownership through forgot/reset-password. The
existing social identity remains attached. Rolling back the migration preserves
nullable passwords to avoid damaging existing social accounts.

## Local mail and frontend URL

From `backend`, run `docker compose -f compose.mail.yaml up -d`.
Set these values in your local `.env` (new `.env.example` already includes them):

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=hello@example.com
FRONTEND_RESET_PASSWORD_URL=http://127.0.0.1:5173/reset-password
SESSION_DRIVER=database
```

Run `php artisan config:clear` and `php artisan migrate`. Open
<http://127.0.0.1:8025>, submit forgot-password for a seeded/registered user,
and inspect the received email. Mailpit captures SMTP without sending externally.
The mail is sent synchronously; no queue worker is needed. The configured URL
must be a trusted frontend reset page URL without query parameters; Laravel adds
URL-encoded `token` and `email`. That page submits them with the new password
and confirmation to reset-password. This task configures the URL; the frontend
reset form is a separate integration. Use HTTPS in production.

Do not use the `log` mailer for reset emails: it writes sensitive links to logs.
Do not log request bodies, passwords, or reset URLs/tokens in application,
proxy, analytics, or frontend error reporting. Never return raw tokens from APIs.
Expired database rows can be pruned with `php artisan auth:clear-resets`.

## Verification

`php artisan test --filter=PasswordTest` covers authentication, wrong current
passwords, strength and confirmation rules, hashing, token storage hashing,
expiration, reuse, generic responses, throttling, session/remember-token
revocation, social-only accounts, and real notification rendering into Laravel's
in-memory mail transport with the frontend URL. The Mailpit procedure above
verifies local SMTP delivery separately.

For a repeatable local SMTP check, with Mailpit running and the local `.env`
configured as above, run `php tests/verify-local-password-mail.php` from
`backend`. It creates a temporary social-only account, calls the API through
Laravel's HTTP kernel, finds the delivered email through Mailpit's API, verifies
the frontend URL, resets the password with the emailed token, and checks that
reuse is rejected. It deletes the temporary account and token afterward; the
captured email remains in Mailpit for inspection (its link is already consumed).
It refuses to run outside the local environment or against a different SMTP host.

Local verification completed on 2026-11-14: migration applied, SMTP email
received in Mailpit, configured frontend URL confirmed, reset succeeded, and
token reuse returned 422.
