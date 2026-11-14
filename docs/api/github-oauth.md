# GitHub signup and login (task 81)

GitHub uses the existing Laravel web session and Sanctum current-user/logout endpoints. No bearer token is sent to the frontend. Login and registration both expose **Continue with GitHub**.

## Configure

1. In GitHub **Settings → Developer settings → OAuth Apps → New OAuth App**, register an application. Set the homepage to the frontend URL and the authorization callback URL to `http://127.0.0.1:8000/auth/github/callback` for local development. Use a separate app and HTTPS callback in production.
2. Set these backend environment variables (never commit the secret):

   ```dotenv
   GITHUB_CLIENT_ID=your-client-id
   GITHUB_CLIENT_SECRET=your-client-secret
   GITHUB_REDIRECT_URI=http://127.0.0.1:8000/auth/github/callback
   FRONTEND_URL=http://127.0.0.1:5173
   ```

3. Run `php artisan migrate` and `php artisan config:clear`. Configure the existing session, CORS and Sanctum stateful domains for the frontend, using the same hostname (`127.0.0.1` or `localhost`) throughout. Production needs HTTPS, secure cookies, and a shared persistent session store. `FRONTEND_URL` is a trusted deployment setting, never a request parameter.
4. Set frontend `VITE_API_BASE_URL=http://127.0.0.1:8000/api` as for password login. Restart the frontend after environment changes.

The OAuth app must be registered by an authorized GitHub account owner; repository configuration alone does not register it or generate credentials.

## Behavior and security

Navigate to `GET /auth/github/redirect`; GitHub returns to `GET /auth/github/callback`. Session-bound state expires in ten minutes and is consumed once, including on denial/errors. The exchange uses PKCE S256. A successful callback regenerates the session and redirects to the frontend root, where existing auth restoration loads the user. Failures redirect to `/login?oauth_error=...` with a fixed safe error code.

Only the durable numeric GitHub `id` identifies the account. Database unique constraints on `(provider, provider_id)` and `(user_id, provider)` prevent duplicates. User and identity creation share one transaction; a duplicate callback rolls back the extra user. Provider tokens are transient and never stored.

The `read:user user:email` scopes allow reading the profile and private email list. Only a valid primary, verified address from `/user/emails` is eligible for a new account. Null, unverified or unavailable email produces a user with null email and an incomplete profile. The existing profile editor collects an available email; no synthetic address or null-email lookup is used. Returning users authenticate by provider ID even if their GitHub name/email changes or email is unavailable. The migration keeps nullable emails on rollback to preserve these users.

Email matches never link existing users automatically. A conflict instructs the person to sign in with their movie app password and open **My profile → Link GitHub**. The ordinary GitHub login flow cannot switch an already signed-in account or silently attach an identity. Existing password signup/login/reset/logout remain available.

## Link an existing account

Sign in normally, open `/profile`, and use **Link GitHub**. Confirm the movie app password, then authorize GitHub. The frontend initializes CSRF protection and sends `POST /api/v1/auth/github/link` with `current_password`. This endpoint requires session authentication, validates the password, and uses the password rate limiter. It returns `data.url`, the GitHub authorization URL, and records the initiating account ID alongside one-time state and PKCE in the session.

The callback only links if the same account is still signed in. It never selects the target by email or a client-supplied user ID. Existing connections cannot be replaced or transferred. Database uniqueness protects both account and provider ownership. Success returns to `/profile?github_linked=1`; denial or conflict returns a safe `github_error`. Profile responses include the boolean `data.github_linked` so the page shows the current connection status. Linking does not change the account email or password. After linking, logout and **Continue with GitHub** should restore the same movie account.

## Verify

Provider-only accounts can add their first email in their authenticated profile session without a local password; that address remains unverified. Changing any existing email still requires a password. Users with an email can establish a local password through the existing email password reset flow.

Run `php artisan test` in `backend` and `npm test` plus `npm run build` in `frontend`. Feature tests mock GitHub and cover signup, returning IDs, missing email, conflicts, state replay, denial, and provider errors. With real credentials, manually check public/private-email signup, repeat login, denial, session restoration and logout. Live GitHub verification requires the registered OAuth app and its credentials.

References: [GitHub OAuth flow](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps), [durable IDs](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/best-practices-for-creating-an-oauth-app).
