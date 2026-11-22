# Administrator user management (AB#152)

The homepage shows **User management** for users with the
`can_access_admin` capability. The app receives that capability in the sign-in
and own-profile responses. Laravel enforces the existing `access-admin` gate
on every admin request.

Admins can inspect account name, email when present, role, join date, and
enabled status. The page asks for confirmation before changing an account.
All administrator accounts are protected, including the signed-in admin.

## API

Use `Accept: application/json` and the existing Sanctum session cookie. Send
`Content-Type: application/json` and the Sanctum CSRF header on PATCH. Both
successful responses use `Cache-Control: private, no-store`.

| Method and path | Contract |
| --- | --- |
| `GET /api/v1/admin/users` | Optional `search` (name or email substring, max 100 characters), `page`, and `per_page` (default 20, max 50). Returns `data` and pagination `meta`. |
| `PATCH /api/v1/admin/users/<id>/status` | JSON body `{"status":"disabled"}` or `{"status":"enabled"}`. Returns the updated summary in `data`. |

The summary contains the string `id`, `name`, nullable `email`, `is_admin`,
`status`, `disabled_at`, and `created_at`. Guest requests receive 401, regular
accounts receive 403, and attempts to change an administrator receive 403.
Missing IDs return 404 and invalid values return 422.

Disabling preserves the account and content, revokes its database sessions,
and replaces its remember token. Protected APIs enforce account status on
each request. Password and social sign-in both check status. After enabling,
the user signs in again under the project's database-session setup.

## Manual verification

1. From the backend directory, run `php artisan migrate` against your local
   development database. Start the API and frontend. Use a configured admin
   account and a separate regular test account.
2. In browser A, sign in as the administrator and open **User management** from
   `http://localhost:5173/`. Search for the regular test account by part of its
   name or email. Clear the search and search again to see all accounts. With
   more than 20 matching accounts, use **Next** and **Previous**.
3. In browser B, sign in as the regular test account. Check that there is no
   homepage link and that `http://localhost:5173/admin/users` displays
   **Administrator access is required**. Leave browser B signed in.
4. In browser A, select **Disable**. Check that **Cancel** preserves the status.
   Reopen and confirm. The row should show **Disabled**. Admin rows should
   display **Protected**.
5. Refresh **My profile** in browser B. The old session should take the user
   to sign-in. A correct password should fail while disabled. If the test
   account has a linked provider, try its social sign-in too.
6. In browser A, **Enable** the regular account and confirm. The row should
   show **Enabled**. In browser B, sign in again and confirm access.
