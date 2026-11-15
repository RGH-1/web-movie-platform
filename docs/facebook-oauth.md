# Facebook OAuth — AB#82

## Setup

Create a Facebook Login app and configure this local callback exactly:

```
http://localhost:8000/auth/facebook/callback
```

Set `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `FACEBOOK_REDIRECT_URI`, and
`FRONTEND_URL` in `backend/.env`. Do not commit credentials. After changing the
environment, run `php artisan config:clear`.

Start authentication at `GET /auth/facebook/redirect`. Socialite retains its
normal state/CSRF validation and the callback is `GET /auth/facebook/callback`.

## Registration and returning users

Facebook registrations use the stable Facebook provider ID, stored in
`social_accounts.provider_user_id`, as their identity. If Facebook supplies a
valid unused email it is stored normally. If Facebook supplies no email (or an
empty email), the local `users.email` and `social_accounts.provider_email`
values are stored as `NULL`; no synthetic address is created.

A supplied malformed email is rejected. A Facebook identity is never linked to
an existing local user merely because emails match. A returning Facebook user is
found by `(provider = facebook, provider_user_id)` before considering email, so
they can sign in even when Facebook omits email later.

Traditional registration, Google, and GitHub still require email. Google also
continues to require Facebook-independent provider verification of its email.

Facebook-only accounts created without an email also have no local password.
They must sign in through Facebook. Adding an email or a local password requires
a separate ownership-verification feature and is intentionally not provided by
profile editing.

## Local testing

Use a Facebook test user whose authorized redirect URI matches the local URL
above. Test both an account that grants the email permission and one that does
not. Confirm that the first callback creates one user and social account, then
sign out and repeat the callback to confirm it signs into the same local user.

Automated callback coverage is in `backend/tests/Feature/OAuthCallbackTest.php`.
