# Development Setup Guide

## Prerequisites

Before running the project locally, install:

* Git
* Node.js and npm
* PHP compatible with the Laravel version used by the project
* Composer
* MySQL

## 1. Clone the Repository

Clone the `web-movie-platform` repository and navigate into it.

## 2. Configure the Laravel Backend

Navigate to the `backend` directory and install the PHP dependencies:

```bash
composer install
```

Create a local environment file by copying `.env.example` to `.env`. On Windows Command Prompt, use:

```bat
copy .env.example .env
```

On macOS, Linux, or Git Bash, use:

```bash
cp .env.example .env
```

Generate the application key if one has not already been generated for the local environment:

```bash
php artisan key:generate
```

## 3. Configure MySQL

Make sure MySQL is running and that you have access to a database for the project.

Update `backend/.env` with your local database settings:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=web_movie_platform
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

Replace the example values with your actual local configuration.

Each developer should keep their own database credentials in their local `.env` file. Never commit that file.

For social login, keep browser-facing URLs on the same hostname as the examples:
`APP_URL=http://localhost:8000`, `FRONTEND_URL=http://localhost:5173`, and
frontend `VITE_API_BASE_URL=http://localhost:8000/api`. Each provider callback is
`http://localhost:8000/auth/{provider}/callback` and must also be registered in
that provider's application settings. Do not mix `127.0.0.1` and `localhost`
during an OAuth round trip, because the session cookie holds the OAuth state.
Keep MySQL's `DB_HOST` independent of these browser URLs.

If upgrading from the earlier GitHub-only implementation, run the pending
migrations normally. The shared social-account migration adopts the existing
provider IDs and preserves linked users; do not drop or truncate the table.

## 4. Verify the Database Connection

From the `backend` directory, run:

```bash
php artisan config:clear
php artisan migrate:status
```

The migration status command should display the database migration status if the connection is configured correctly.

## 5. Start the Backend

From the `backend` directory, run:

```bash
php artisan serve --host=localhost --port=8000
```

Laravel will display the local address where the application is running.

## 6. Start the Frontend

Open another terminal and navigate to the `frontend` directory:

```bash
npm install
npm run dev -- --host localhost
```

Vite will display the local address of the React application.

## 7. Frontend API Configuration

The React frontend uses Vite environment variables to configure the Laravel API base URL.

1. Navigate to the `frontend` directory.
2. Create a `.env.local` file if one does not already exist.
3. Add the following configuration:

```dotenv
VITE_API_BASE_URL=http://localhost:8000/api
```

4. Start the Laravel backend and React development server.
5. Open the React application and verify that the backend API status reports a successful connection.

Do not commit `.env.local`. Use `.env.example` as the template for documenting required environment variables.

## 8. TMDB API Configuration

The application uses The Movie Database (TMDB) API to retrieve movie information.

### Obtain API Credentials

1. Create an account at https://www.themoviedb.org.
2. Navigate to Settings > API.
3. Click Request an API Key.
4. Choose Yes (This is for my own personal use).
5. Fill in the information (you can use fake first and last name and fake phone number) - for the application URL just put it http://localhost
3. Request API access for the project.
4. Copy your API Read Access Token.

### Configure Laravel

Add the following variables to `backend/.env`:

```dotenv
TMDB_BASE_URL=https://api.themoviedb.org/3
TMDB_API_READ_TOKEN=your_token_here
```
Replace `your_token_here` with your actual token.

### Verify Configuration

From the `backend` directory, run:

```bash
php artisan config:clear
php artisan tinker
```
Inside Tinker, verify the token is configured:

```php
filled(config('services.tmdb.token'))
```
Expected result: `true`.

To verify access to TMDB:

```php
$response = Illuminate\Support\Facades\Http::withToken(
    config('services.tmdb.token')
)->get(config('services.tmdb.base_url') . '/configuration');

$response->status();
```
Expected HTTP status: `200`.


## 9. Configure and verify session authentication

Run Laravel and Vite on the same hostname. For the default local ports, set
these values in `backend/.env`:

```dotenv
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
SESSION_DOMAIN=null
```

If Vite uses a different port, update both frontend settings. From `backend`,
run `php artisan config:clear` after editing `.env`. If you use database
sessions, apply the existing migrations to your development database.

Run `php artisan test --filter=LoginLogoutTest` from `backend` to verify the
login and logout flow against the test database.

For a manual check with an existing development user, keep the same cookie
jar and send `Origin: http://localhost:5173` and `Accept: application/json`.
Request `GET /sanctum/csrf-cookie` and expect `204`. Send the URL-decoded
`XSRF-TOKEN` cookie as `X-XSRF-TOKEN` on `POST /api/v1/auth/login`. Expect `200`
with the user's ID, name, and email. Use the updated cookies and CSRF token
to call `POST /api/v1/auth/logout` and expect `204`. Repeat logout with the new
guest cookies and CSRF token and expect `401`.

See the [authentication API contract](api/auth-contract.md) for request
bodies and response examples.

Use `localhost` consistently for the React app, Laravel app, API base URL,
Sanctum origin, and OAuth redirect URIs. Do not mix `localhost` and
`127.0.0.1` in a browser session: they are different cookie origins. The
committed Sanctum example retains `127.0.0.1:5173` so existing loopback-based
test and development clients remain supported.


## Important Notes

* Do not commit `.env` files, passwords, API keys, or other secrets.
* Commit `composer.lock` and `package-lock.json` so the team can install consistent dependency versions.
* When dependencies change, install them using the appropriate Composer or npm commands.
* If you encounter environment-specific problems, document the solution so the rest of the team can benefit from it.
