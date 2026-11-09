# AB#24 — Backend Authentication Test Report

**Date:** November 9, 2026

**Project:** Web Movie Platform

**Feature:** Laravel Authentication API

**Testing framework:** PHPUnit / Laravel Feature Tests

## 1. Objective

Verify the primary backend authentication workflows, including successful and unsuccessful registration, login, logout, authenticated-user retrieval, protected endpoint access, and the exclusion of sensitive information from API responses.

## 2. Testing Environment

Automated tests were executed locally using Laravel's testing framework.

The PHPUnit configuration specifies:

- `APP_ENV=testing`
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=:memory:`
- `SESSION_DRIVER=array`
- `CACHE_STORE=array`
- `MAIL_MAILER=array`

Database-dependent tests use Laravel's `RefreshDatabase` trait.

Authentication test data is created using test factories and fixed test credentials rather than production accounts.

External HTTP requests in the route-access tests are faked, and unexpected requests are prevented.

## 3. Test Coverage

### Registration — RegistrationTest.php

Verified:

- Successful account creation.
- User name and email normalization.
- Password hashing.
- Required-field validation.
- Invalid names, emails, and passwords.
- Minimum password length of 12 characters.
- Maximum password length of 72 UTF-8 bytes.
- Password confirmation validation.
- Duplicate email rejection, including case differences.
- Prevention of registration by an already authenticated user.
- Rejection of requests without a stateful session.

### Login and Logout — LoginLogoutTest.php

Verified:

- Successful login with valid credentials.
- Authentication session creation and regeneration.
- Rejection of incorrect passwords and unknown email addresses.
- Consistent error messages for invalid credentials.
- Input validation.
- Prevention of switching accounts through repeated login.
- Successful logout and session invalidation.
- Rejection of unauthorized logout.
- CSRF and stateful-origin requirements.
- Login rate limiting.

### Authenticated User — AuthenticatedUserTest.php

Verified:

- Retrieval of the authenticated user's information.
- Rejection of unauthenticated requests.
- Prevention of accessing another user's information through query parameters.
- Authentication restoration for users with incomplete profiles.
- Session-based authentication restoration.
- Removal of protected access after logout.

### Protected Endpoints — ApiRouteAccessTest.php

Verified that guests are rejected from protected endpoints, including:

- `GET /api/v1/me`
- `GET /api/v1/me/profile`
- `PATCH /api/v1/me/profile`
- `POST /api/v1/auth/logout`
- `GET /api/v1/movies/search`
- `GET /api/v1/movies/{tmdbId}`

The movie-details endpoint was added to the protected-endpoint data provider as part of AB#24.

Tests verify HTTP 401 responses, JSON error formatting, and the absence of unintended external HTTP requests.

## 4. Sensitive Information Verification

Registration, login, and current-user tests assert the exact expected API response structure.

The tested public account response contains:

- `id`
- `name`
- `email`

These exact-response assertions prevent unexpected fields, such as password hashes, from appearing in successful authentication responses.

Invalid login tests also verify generic error messages rather than information that reveals whether an account exists.

## 5. Test Execution Results

**Command:**

```powershell
php artisan test --filter="RegistrationTest|LoginLogoutTest|AuthenticatedUserTest|ApiRouteAccessTest"
```

**Result:** 50 tests passed.

No failures were reported.

## 6. Changes Made

### Added protected movie-details endpoint coverage

Updated `ApiRouteAccessTest.php` to include:

```php
'movie details' => [
    'GET',
    '/api/v1/movies/157336',
],
```

This extends the existing PHPUnit data-driven tests to verify authentication requirements for the movie-details endpoint.

Existing authentication tests were reused rather than duplicated.

## 7. Acceptance Criteria Assessment

| Requirement | Result |
|---|---|
| Successful registration | PASS |
| Invalid and duplicate registration | PASS |
| Successful login and invalid credentials | PASS |
| Logout behavior | PASS |
| Authenticated-user retrieval | PASS |
| Protected endpoints reject guests | PASS |
| Sensitive information excluded from responses | PASS |
| Isolated test configuration | VERIFIED |

## 8. Conclusion

The targeted Laravel authentication suite passed all 50 tests.

Existing tests provide broad coverage of the authentication requirements. AB#24 extends protected-endpoint coverage to the newer movie-details API route and documents the verification results.

The complete backend regression