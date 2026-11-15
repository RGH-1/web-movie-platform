
# Google OAuth Integration — AB#80

## Overview

Google OAuth authentication is integrated using Laravel Socialite and the shared OAuth infrastructure introduced in AB#79.

Google authentication supports:
- First-time user registration
- Returning user login
- Verified Google email validation
- Existing Laravel session and Sanctum authentication
- OAuth cancellation and error handling
- Stable social account mapping

## Google Cloud Setup

1. Open Google Cloud Console.
2. Create or select the project for the movie platform.
3. Open Google Auth Platform.
4. Configure the OAuth consent screen.
5. Select External audience.
6. Add development test users if the application is in Testing mode.
7. Create an OAuth client with application type Web application.

Authorized redirect URI:

http://127.0.0.1:8000/auth/google/callback

The registered URI must match the Laravel callback URL exactly.

## Backend Configuration

Add the following variables to backend/.env:

GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback

FRONTEND_URL=http://127.0.0.1:5173

Never commit real OAuth credentials.

After updating the environment:

php artisan config:clear

Start Laravel:

php artisan serve --host=127.0.0.1 --port=8000

## Authentication Flow

Start Google authentication by opening:

GET /auth/google/redirect

After Google authentication, the provider redirects to:

GET /auth/google/callback

The shared OAuth controller then:

1. Validates the provider and OAuth state.
2. Retrieves the Google identity.
3. Looks up an existing social account by Google provider ID.
4. Creates a new local user if appropriate.
5. Rejects unsafe automatic email-based account linking.
6. Requires verified Google email for new registrations.
7. Establishes the Laravel web authentication session.
8. Redirects the browser to the React frontend.

## Manual Verification

Successfully tested:

- First-time Google authentication
- New local user creation
- New social_accounts record creation
- Returning Google login
- Reuse of existing local user and provider identity
- No duplicate users or social accounts
- Authenticated redirect to React
- Simulated OAuth cancellation

Cancellation test URL:

http://127.0.0.1:8000/auth/google/callback?error=access_denied

Expected redirect:

http://127.0.0.1:5173/login?oauth_error=cancelled

This cancellation test exercises Laravel's callback handling, not Google's actual consent screen.

## Security

- Google credentials remain in backend/.env.
- OAuth state validation remains enabled.
- New Google registrations require a verified email.
- Accounts are identified using their stable Google provider ID.
- Existing users are never automatically linked by email alone.
- OAuth access and refresh tokens are not stored.
- The Laravel session identifier is regenerated after login.

## Dependencies

Requires AB#79 shared OAuth infrastructure.

The frontend Google login button is a separate integration responsibility if it is not already implemented.
