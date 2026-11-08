# Authentication API contract (AB#18)

Status: Sprint 2 proposal for team review. The routes and configuration below describe the intended behavior for the implementation tasks.

## Authentication approach

The first-party React app uses Laravel Sanctum's SPA authentication with the `web` session guard. Laravel sets an HttpOnly session cookie and provides a separate `XSRF-TOKEN` cookie for CSRF protection. The browser sends the cookies on requests to Laravel. React keeps only the public user fields in memory and restores the user through `GET /api/v1/me` on page load. The API issues no bearer token to this client.

Version new application endpoints under `/api/v1`. Keep `/api/health` and the existing TMDB integration endpoint at their current URLs and accessible without login. Movie browsing requires a signed-in account under the updated product flow. Apply auth:sanctum and profile.complete to production movie routes; see profile-contract.md. The health and temporary diagnostic exceptions remain public.

## Endpoints

Send `Accept: application/json` on every request and `Content-Type: application/json` on requests with a JSON body. Send credentials with all auth requests. Initialize CSRF before registration or login and include the current `X-XSRF-TOKEN` header on every POST. The framework's CSRF route is at the backend origin, outside `/api`.

| Method and path | Access | Request | Success | Expected failures |
| --- | --- | --- | --- | --- |
| `GET /sanctum/csrf-cookie` | Public | No body | `204`; initializes CSRF cookies | — |
| `POST /api/v1/auth/register` | Signed out | `name`, `email`, `password`, `password_confirmation` | `201`; creates account, signs in, returns user | `409`, `419`, `422` |
| `POST /api/v1/auth/login` | Signed out | `email`, `password` | `200`; signs in, returns user | `401`, `409`, `419`, `422`, `429` |
| `POST /api/v1/auth/logout` | Authenticated, `auth:sanctum` | No body | `204`; ends the current session | `401`, `419` |
| `GET /api/v1/me` | Authenticated, `auth:sanctum` | No body | `200`; returns the current user | `401` |

Registration request:

```json
{
  "name": "Sam Example",
  "email": "sam@example.com",
  "password": "sample passphrase 2026",
  "password_confirmation": "sample passphrase 2026"
}
```

Login request:

```json
{
  "email": "sam@example.com",
  "password": "sample passphrase 2026"
}
```

Registration, login, and current-user success responses use the same JSON shape:

```json
{
  "data": {
    "id": 42,
    "name": "Sam Example",
    "email": "sam@example.com"
  }
}
```

`id` is an integer; `name` and `email` are strings. Return only these fields. Passwords, hashes, and internal session or token values never appear in JSON. A `204` has no response body. Registration and login regenerate the session ID; logout invalidates the current session and regenerates its CSRF token. Email verification, password reset, social sign-in, and optional profile fields are outside this initial contract.

## Validation

| Field | Registration | Login |
| --- | --- | --- |
| `name` | Required string; trim outer whitespace; 1–255 characters | Not used |
| `email` | Required string; trim, lowercase, valid RFC email format, at most 255 characters, unique in `users.email` | Same format and normalization; no uniqueness or existence validation |
| `password` | Required string; at least 12 characters, at most 72 UTF-8 bytes for bcrypt, no NUL byte. No mandatory character classes. | Required string; at most 72 UTF-8 bytes and no NUL byte. No registration minimum on login. |
| `password_confirmation` | Required string; must match `password` exactly; report mismatch on `password_confirmation` | Not used |

Never trim or normalize passwords or confirmations. Use only validated fields and store only the password hash. Keep the existing unique database index on `users.email`; if a concurrent registration hits that index, return the same field error as normal duplicate validation. Use the same email normalization before future email updates.

### Laravel rule examples for the implementation tasks

Use these as reference for the future Form Requests. Laravel's default trim middleware handles the outer whitespace for `name`; it already excludes `password` and `password_confirmation`. In each Form Request, normalize a string email in `prepareForValidation()`; leave non-string inputs for the validator to reject.

```php
protected function prepareForValidation(): void
{
    $email = $this->input('email');

    if (is_string($email)) {
        $this->merge(['email' => mb_strtolower(trim($email))]);
    }
}
```

Example rule arrays (`$passwordBytes` is the shared rule used in both):

```php
$passwordBytes = static function (string $attribute, mixed $value, \Closure $fail): void {
    if (strlen($value) > 72 || str_contains($value, "\0")) {
        $fail('The password must be at most 72 bytes and cannot contain a NUL character.');
    }
};

$registrationRules = [
    'name' => ['required', 'string', 'max:255'],
    'email' => ['bail', 'required', 'string', 'max:255', 'email:rfc', 'unique:users,email'],
    'password' => ['bail', 'required', 'string', 'min:12', $passwordBytes],
    'password_confirmation' => ['bail', 'required', 'string', 'same:password'],
];

$loginRules = [
    'email' => ['bail', 'required', 'string', 'max:255', 'email:rfc'],
    'password' => ['bail', 'required', 'string', $passwordBytes],
];
```

## Errors

| HTTP status | When | Frontend handling |
| --- | --- | --- |
| `401` | Login credentials do not match, or a protected request has no authenticated session | Show a generic form error for failed login. For `/me` or another protected request, clear the local signed-in user. |
| `409` | An authenticated session attempts registration or login | Keep the current session; use `/me` to refresh the UI. Sign out before switching accounts. |
| `419` | A POST is missing a valid CSRF token or its CSRF state has expired | Refresh CSRF state and let the user submit again. If a protected request also lost its session, ask the user to sign in. |
| `422` | One or more submitted fields fail validation, including a duplicate registration email | Show the messages under their fields using `errors[field]`. |
| `429` | Too many login requests; initial limit is 10 per minute per client IP | Show a retry message and honor `Retry-After` when provided. |
| `500` | Unexpected server failure | Show a generic error. The server logs the cause without credentials or secrets. |

A protected POST can fail CSRF with `419` before reaching the authentication check. With valid CSRF and no signed-in user, logout returns `401`.

Validation error (`422`):

```json
{
  "message": "The email has already been taken.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

Non-validation errors provide a top-level `message`; a field error map is not required. For example, failed login (`401`) uses the same message for an unknown email and a wrong password:

```json
{
  "message": "Invalid email or password."
}
```

Unauthenticated access to a protected route can return `{"message":"Unauthenticated."}`. Frontend decisions should use the status, route, and field keys; message text can change. A network or CORS failure may have no JSON response.

## Implementation handoff

- **Laravel:** Enable `$middleware->statefulApi()` in `backend/bootstrap/app.php`. Register the new routes in `backend/routes/api.php` under `Route::prefix('v1')`; Laravel supplies the `/api` prefix. Apply `auth:sanctum` to `/me` and logout. Use the `web` guard for credential checks and session login/logout. Explicitly return a JSON `409` for signed-in registration/login instead of using a redirecting guest flow. Rate limit the login route and keep auth responses JSON.
- **Local configuration:** Use one hostname in both browser URLs, e.g. React at `http://127.0.0.1:5173` and Laravel at `http://127.0.0.1:8000`. Use `APP_URL=http://127.0.0.1:8000`, `SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5173`, and a host-only session cookie (`SESSION_DOMAIN` unset or `null`). If Vite uses another port, update both the stateful origin list and CORS configuration. With the database session driver, run the existing migrations in your development database. Configure Laravel CORS for `api/*` and `sanctum/csrf-cookie`, allow the exact React origin, allow the JSON and XSRF request headers, and set `supports_credentials` to `true`.
- **React:** Keep `VITE_API_BASE_URL=http://127.0.0.1:8000/api`. Add `withCredentials: true` and `withXSRFToken: true` to the shared Axios client in `frontend/src/services/api.js` when implementing auth. Call the CSRF route at the backend origin, then use `/v1/auth/...` and `/v1/me` relative to the existing client base. Read `response.data.data` for the user and clear it after successful logout. A successful logout has no JSON to parse.
- **Deployment:** Keep the frontend and backend on the same site, such as a single HTTPS origin or HTTPS subdomains of the same parent domain. Revisit the CORS, stateful origin, and cookie settings for the actual deployment hosts.

Example frontend call sequence for the future React implementation (after enabling both Axios options):

```js
import api from './api';

const csrfUrl = new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href;

export async function register(fields) {
    await api.get(csrfUrl);
    return (await api.post('/v1/auth/register', fields)).data.data;
}

export async function login(email, password) {
    await api.get(csrfUrl);
    return (await api.post('/v1/auth/login', { email, password })).data.data;
}

export async function getCurrentUser() {
    return (await api.get('/v1/me')).data.data;
}

export async function logout() {
    await api.post('/v1/auth/logout');
}
```

## Verification for the implementation PRs

1. Register valid input (`201`), then request `/me` (`200`) using the same browser session. Confirm the user shape and that the stored password is hashed.
2. Register with a missing field, invalid email, short or oversized password, mismatched confirmation, and a duplicate email (including different casing). Expect `422` and the appropriate field keys.
3. With no session, log in with valid credentials (`200`). A malformed request returns `422`; an unknown email or incorrect password returns the same `401` message. Repeated login requests hit `429`.
4. Confirm unauthenticated `/me` returns JSON `401`. Confirm logout with valid CSRF returns `401` when signed out and `204` when signed in. After `204`, `/me` returns `401`.
5. In the browser, verify the CSRF request goes to `/sanctum/csrf-cookie`, the subsequent POST includes `X-XSRF-TOKEN`, and login persists across a refresh. Confirm a POST without the CSRF header fails with `419`. Check that the existing health and TMDB integration routes still work signed out.
6. While signed in, attempt registration or login with valid CSRF. Confirm `409` and verify `/me` still returns the original user.

References: [Laravel 12 Sanctum SPA authentication](https://laravel.com/docs/12.x/sanctum#spa-authentication), [Laravel 12 authentication](https://laravel.com/docs/12.x/authentication), [Laravel 12 JSON validation errors](https://laravel.com/docs/12.x/validation#validation-error-response-format).



## Authenticated-user endpoint implementation — AB#22

`GET /api/v1/me` returns the user associated with the current session.
The route uses `auth:sanctum` and is accessible to signed-in accounts
whose profiles are incomplete.

Send `Accept: application/json` and the session cookie. This GET request
takes no body and does not require a CSRF token.

Successful response, `200`:

```json
{
  "data": {
    "id": 42,
    "name": "Sam Example",
    "email": "sam@example.com"
  }
}
```

The response explicitly selects `id`, `name`, and `email`. The profile
editor uses `/api/v1/me/profile` for the extended profile information.

Without a valid authenticated session, return `401`:

```json
{
  "message": "Unauthenticated."
}
```

The user is resolved from the request's authentication context. Query
parameters such as `id` or `user_id` cannot select another account.

### Frontend integration

The shared Axios client already sends credentials. On application startup,
request `/v1/me` relative to its `/api` base URL. Store the returned user
in the frontend's authentication state.

```js
const response = await api.get('/v1/me');
const user = response.data.data;
```

A `401` means the frontend should use its signed-out state. Other failures,
such as a network error or `500`, should be handled as an inability to
check the session.

### Verification

From `backend`, run:

```bash
php artisan test --filter=AuthenticatedUserTest
php artisan test
```

The dedicated tests cover the exact response fields, guest rejection,
account ownership, incomplete-profile access, and session access before
and after logout.

For a manual check, sign in through the React login page, then use the
same browser session to request `/api/v1/me`. Expect `200` and the
signed-in user's fields. A request from a private browser window with
no session should receive `401`.


## Protected route policy — AB#23

The React application uses Sanctum SPA authentication with Laravel's
`web` session guard. The shared Axios client sends cookies and CSRF
headers. The backend enables `statefulApi()` and protects account and
application routes with `auth:sanctum`.

### Access matrix

| Endpoint | Access |
| --- | --- |
| `GET /api/health` | Public |
| `GET /sanctum/csrf-cookie` | Public |
| `GET /api/integration/tmdb/search` | Public temporary integration check |
| `POST /api/v1/auth/register` | Guest entry point |
| `POST /api/v1/auth/login` | Guest entry point, rate limited |
| `POST /api/v1/auth/logout` | Authenticated |
| `GET /api/v1/me` | Authenticated |
| `GET /api/v1/me/profile` | Authenticated |
| `PATCH /api/v1/me/profile` | Authenticated |
| `GET /api/v1/movies/search` | Authenticated with a complete profile |

Guest entry points still enforce validation and CSRF protection.
Registration and login reject already signed-in users with `409`.

### Adding protected routes

In `backend/routes/api.php`, add account routes inside the existing
`auth:sanctum` group:

```php
Route::middleware('auth:sanctum')->group(function () {
    // Account routes go here.

    Route::middleware('profile.complete')->group(function () {
        // Movie browsing and other completion-dependent features go here.
    });
});
```

This group is inside the existing `v1` prefix. Laravel supplies `/api`,
so a route declared as `/me` becomes `/api/v1/me`.

Register the future movie details route inside the nested
`profile.complete` group. Constrain its TMDB ID parameter to digits
to distinguish it from `/movies/search`.

Authentication-state restoration, profile repair, and logout belong
directly in the authenticated group. Public entry points belong outside
that group. Apply resource ownership checks separately when an endpoint
can access another user's records.

### Failure responses

An unauthenticated protected request returns `401`:

```json
{
  "message": "Unauthenticated."
}
```

API authentication errors use JSON even when a caller accepts HTML.
An authenticated account with an incomplete profile receives `403`
and `code: PROFILE_INCOMPLETE` on completion-dependent features.

A state-changing request with missing or invalid CSRF protection can
receive `419` before authentication is evaluated. A public endpoint may
also return validation or rate-limit errors. Public access means it
does not require an existing authenticated account.

### Local session configuration

Use matching hostnames across the frontend URL, API base URL,
credentialed CORS origin, and Sanctum's stateful domain list. The
committed examples use `127.0.0.1`.

### Verification

From `backend`, run:

```bash
php artisan route:list --path=api -v
php artisan test --filter=ApiRouteAccessTest
php artisan test
```

The access tests verify public endpoints, guest rejection, JSON errors,
authenticated access, and profile-completion boundaries. Existing
authentication tests verify session cookies, CSRF, and logout behavior.