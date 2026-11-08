# Profile ownership and validation verification

Work item: AB#34

## Run the checks

From backend:

    php artisan test --filter=Profile
    php artisan test

The tests use RefreshDatabase and the in-memory SQLite configuration in
phpunit.xml. They do not need a live TMDB connection or change the local
development database.

## Coverage by test file

| File | Coverage |
| --- | --- |
| ProfileTest.php | Owner retrieval and updates, response allowlist, normalization, genres, invalid input, email confirmation, guest rejection, and CSRF requirement. |
| ProfileCompletionTest.php | Complete/incomplete accounts, optional-field independence, middleware denial, profile repair and recovery, client status override rejection, and public-route exceptions. |
| ProfileOwnershipTest.php | Cross-account update attempts, whole-record preservation on rejection, protected/unknown fields including null, password ownership, and a real session-cookie/CSRF flow. |

## Additional ownership checks

- Query-string IDs cannot select another account: only the authenticated
  owner's data changes, and the other account's full database record stays
  unchanged.
- Body identifiers are rejected with 422; neither account changes.
- Protected keys (ID, role, permissions, verification state, remember token,
  timestamps, and password) and unknown keys reject the entire update,
  including when their submitted value is null.
- Invalid fields do not partially save valid changes in the same request.
- Another account's password cannot authorize an email change.
- Browser-session login permits owner retrieval and an update with valid
  CSRF. An invalid CSRF token returns 419 without changing the saved bio.

Completion middleware is exercised on test-only routes. Production feature
owners must apply auth:sanctum and profile.complete as documented in
profile-contract.md. These tests do not claim that future movie endpoints
or frontend access guards have already been implemented.
