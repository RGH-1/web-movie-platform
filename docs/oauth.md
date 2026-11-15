
# Multi-Provider OAuth Infrastructure

## Overview

The backend supports reusable OAuth authentication through Laravel Socialite.

Supported providers:
- Google
- GitHub
- Facebook

OAuth identities are connected to local application users through the `social_accounts` table.

The implementation uses Laravel's existing web session guard and integrates with Sanctum-protected API routes.

## Authentication Routes

GET /auth/{provider}/redirect

Starts OAuth authentication by redirecting the browser to the selected provider.

GET /auth/{provider}/callback

Processes the provider's authorization response and establishes a Laravel session.

Allowed provider values:
- google
- github
- facebook

Both routes use Laravel web middleware and rate limiting.

## Environment Configuration

Configure the following variables in backend/.env:

GOOGLE_CLIENT_ID
GOOGLE_CLIENT_SECRET
GOOGLE_REDIRECT_URI

GITHUB_CLIENT_ID
GITHUB_CLIENT_SECRET
GITHUB_REDIRECT_URI

FACEBOOK_CLIENT_ID
FACEBOOK_CLIENT_SECRET
FACEBOOK_REDIRECT_URI

FRONTEND_URL

Local development callbacks:

http://localhost:8000/auth/google/callback
http://localhost:8000/auth/github/callback
http://localhost:8000/auth/facebook/callback

Register the exact callback URL with each OAuth provider.

Never commit real OAuth credentials.

## Local development

Use one browser hostname throughout the local stack: React at
`http://localhost:5173`, Laravel at `http://localhost:8000`, and the API at
`http://localhost:8000/api`. Start the applications with:

```bash
# backend
php artisan serve --host=localhost --port=8000

# frontend
npm run dev -- --host localhost
```

Configure the Google, GitHub, and Facebook provider consoles with the exact
localhost callback URLs above. `localhost` and `127.0.0.1` are different
browser origins, so do not mix them in an OAuth or Sanctum session. The
example Sanctum list retains `127.0.0.1:5173` for compatible loopback clients.

## Database

The `social_accounts` table contains:

- id
- user_id
- provider
- provider_user_id
- provider_email
- created_at
- updated_at

Constraints:

- Unique (provider, provider_user_id)
- Unique (user_id, provider)
- Foreign key to users with cascading deletion

OAuth access and refresh tokens are not persisted.

## Authentication Flow

1. The browser navigates to the backend redirect endpoint.
2. Laravel validates the provider allowlist and credentials.
3. Socialite redirects the user to the provider.
4. The provider redirects the browser to Laravel's callback.
5. Socialite validates OAuth state and retrieves the provider identity.
6. Laravel searches for an existing social account using provider and provider_user_id.
7. Existing linked identities authenticate their local user.
8. New identities are checked against the registration policy.
9. A new user and social account are created transactionally when permitted.
10. Laravel creates a web authentication session and redirects to the configured frontend URL.

## Account Security

- OAuth uses session-based state validation.
- Unsupported providers are rejected.
- Social identities use provider-specific IDs rather than email addresses as their primary key.
- Existing local accounts are never automatically linked solely by matching email.
- New Google registrations require a verified email.
- GitHub registration relies on Socialite's verified primary-email retrieval behavior.
- Facebook registrations accept a missing email and store it as `NULL`; no placeholder email is generated.
- Returning Facebook identities authenticate by their provider ID even when Facebook no longer returns an email.
- Google and GitHub registrations still require email, and Google still requires a verified provider email.
- Passwordless users can establish local passwords through the existing email password-reset flow.
- Session identifiers are regenerated after successful authentication.

## Frontend Integration

The frontend should navigate the browser to:

http://localhost:8000/auth/google/redirect
http://localhost:8000/auth/github/redirect
http://localhost:8000/auth/facebook/redirect

After successful authentication, Laravel redirects to FRONTEND_URL.

The frontend can call GET /api/v1/me to retrieve the authenticated user.

The frontend must use Sanctum's existing stateful cookie and CSRF configuration for authenticated API requests.

On failure, Laravel redirects to:

{FRONTEND_URL}/login?oauth_error={error_code}

The frontend should translate error codes into user-friendly messages.

## Known Limitations

- OAuth credentials must be configured before real provider login can work.
- Explicit account linking and unlinking are not implemented.
- OAuth login currently rejects users who are already authenticated.
- Missing provider emails are rejected for new Google and GitHub registrations.
- Real provider/browser end-to-end testing is still required.
- Frontend social login buttons and OAuth error handling are separate integration work.

## Automated Tests

OAuthRedirectTest.php
OAuthCallbackTest.php
OAuthPasswordlessTest.php

Run:

php artisan test --compact

Tests cover provider validation, account creation, returning identities, conflict prevention, invalid OAuth state, session integration, logout, and passwordless account compatibility.
