# User profile contract

Parent story: 30 — Manage User Profiles  
Work item: AB#31 — Define user profile fields and validation rules  
Status: Proposed for team review

## Profile fields

Store the initial profile on the existing `users` record. Registration
already collects `name` and `email`. The profile implementation will
add a nullable `bio` field.

| Field | Required on the account | Who can edit it | Validation |
| --- | --- | --- | --- |
| `name` | Yes | Account owner | Trim surrounding whitespace. Require a string with 1–255 characters after trimming. |
| `email` | Yes | Account owner, with current-password confirmation when the address changes | Trim and lowercase before validation. Require a valid RFC email address, at most 255 characters. The address must be unique in `users.email`, excluding the account owner's existing record. |
| `bio` | No | Account owner | Accept `null` or plain text up to 500 characters after trimming. Store a blank bio as `null`. |
| `id` | System assigned | Server only | Return the existing user ID as an integer. |
| `favorite_genres` | No | Account owner | A JSON list of up to five distinct genre slugs from the supported list. Send `[]` to clear it. |

Store the initial profile on the existing `users` record. Registration
collects `name` and `email`. After registration, users can personalize
their profile with a bio and favorite genres.

A basic profile is complete when the account has a valid name and email.
The bio and favorite genres are optional. Users can add, change, or clear
them at any time.

Possible additions include a profile image and a favorite movie. A profile
image needs an upload and storage design. A favorite movie needs a rule
for validating and displaying a TMDB movie reference. Define those
contracts before adding either field to the profile editor.

### Favorite genres

The frontend displays human-readable labels and submits the corresponding
slugs. The initial supported slugs are `action`, `adventure`, `animation`,
`comedy`, `crime`, `documentary`, `drama`, `fantasy`, `horror`, `mystery`,
`romance`, `science-fiction`, and `thriller`.

For a user with no selected genres, return `favorite_genres: []`, including
for accounts created before this field was added. When a profile update
includes `favorite_genres`, replace the whole selection. When the field
is omitted, keep the current selection.

Reject `null`, duplicate slugs, unknown slugs, and lists with more than
five entries. Genre slugs describe the user's preferences. They do not
change the results returned by movie search.

## Protected fields and ownership

Use the authenticated user to identify the record to update. The
client cannot select another user through a URL parameter, query
parameter, or request body.

The only editable profile fields are `name`, `email`, `bio`, and `favorite_genres`.
Treat `id`, roles, permissions, `email_verified_at`, `remember_token`,
`created_at`, and `updated_at` as system-managed. Handle password
changes in a separate account flow.

Reject protected and unknown keys in the update body, including keys
whose values are `null`. Persist only the validated `name`, `email`,
`bio`, and `favorite_genres` fields. The `current_password` field is a request-only
credential. Never persist, log, or return it.

When the normalized email changes, verify `current_password` against
the signed-in user's password. Clear `email_verified_at` in the same
database update. If any validation fails, save none of the changes.

## Laravel validation reference

Use a dedicated `UpdateProfileRequest` when implementing the endpoint.
Normalize the email in `prepareForValidation()`. Compare the normalized
value with the account's current email to decide whether password
confirmation is required. Preserve the submitted password exactly.

These are the editable field rules inside the Form Request. `$user`
is the authenticated user model, and `Rule` is
`Illuminate\Validation\Rule`.

```php
$supportedGenres = [
    'action',
    'adventure',
    'animation',
    'comedy',
    'crime',
    'documentary',
    'drama',
    'fantasy',
    'horror',
    'mystery',
    'romance',
    'science-fiction',
    'thriller',
];

return [
    'name' => ['sometimes', 'required', 'string', 'max:255'],
    'email' => [
        'sometimes',
        'bail',
        'required',
        'string',
        'max:255',
        'email:rfc',
        Rule::unique('users', 'email')->ignore($user),
    ],
    'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
    'favorite_genres' => ['sometimes', 'array', 'list', 'max:5'],
    'favorite_genres.*' => [
        'bail',
        'required',
        'string',
        'distinct:strict',
        Rule::in($supportedGenres),
    ],
];
```

When the email changes, apply `required`, `string`, and
`current_password:web` to `current_password`, in that order with `bail`.
Otherwise the frontend should omit it and the backend can disregard it.

Check for unknown keys and require at least one editable key in
addition to the field rules above. Check key presence rather than
whether a value is filled, so `{"bio": null}` is accepted. Keep the
database's unique email index. If a concurrent update hits that index,
return the same email validation error as a duplicate detected earlier.



## Profile API

| Method and path | Access | Success |
| --- | --- | --- |
| `GET /api/v1/me/profile` | Signed in with `auth:sanctum` | `200` with the current user's profile |
| `PATCH /api/v1/me/profile` | Signed in with `auth:sanctum` and valid CSRF protection | `200` with the complete updated profile |

Use `/api/v1/me` for authentication state and `/api/v1/me/profile`
for the profile editor. The profile endpoints are private to the
account owner. A future public profile needs a separate response
that excludes the account's email.

Send `Accept: application/json` on both requests and
`Content-Type: application/json` on `PATCH`. Use the session cookie
and CSRF header defined in the [authentication API contract](auth-contract.md).

### Update request

`PATCH` accepts `name`, `email`, `bio`, `favorite_genres`, and the request-only
`current_password` field. Include at least one editable field.
Omitted fields retain their current values. An explicit `null` is
valid for `bio` and invalid for `name` or `email`.

Update a name and bio:

```json
{
  "name": "Sam Example",
  "bio": "I like science fiction and classic mysteries."
}
```

Clear the bio:

```json
{
  "bio": null
}
```

Updating favourite genres:

```json
{
  "favorite_genres": ["science-fiction", "mystery"]
}
```

```json
{
  "favorite_genres": []
}
```

Change the email used for sign-in:

```json
{
  "email": "sam.new@example.com",
  "current_password": "example passphrase"
}
```

### Success response

Both endpoints return the full profile. Always include `bio`, using
`null` when it is empty.

```json
{
  "data": {
    "id": 42,
    "name": "Sam Example",
    "email": "sam@example.com",
    "bio": "I like classic mysteries.",
    "favorite_genres": ["science-fiction", "mystery"]
  }
}
```

### Error responses

| Status | When |
| --- | --- |
| `401` | The request has no authenticated session. |
| `419` | A `PATCH` request has a missing, invalid, or expired CSRF token. This check may fail before authentication is evaluated. |
| `422` | An editable field is invalid, the email is already in use, password confirmation fails, a protected or unknown key is present, or the body contains no editable fields. |

Use Laravel's JSON validation format for `422`:

```json
{
  "message": "The email has already been taken.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

Use `errors.current_password` for missing or incorrect confirmation.
Use the submitted key for a protected or unknown field, such as
`errors.role`. Use `errors.profile` for a request with no editable
fields. The frontend uses status codes and error keys to decide what
to show. Display `bio` as plain text.
Use `errors.favorite_genres` for an invalid list or too many selections.
Use indexed keys such as `errors["favorite_genres.0"]` for an invalid or
duplicate genre. The frontend can display these messages under the genre
selector.

### Acceptance checks for implementation

1. A signed-in user can read their own profile. A guest receives `401`
   when authentication is evaluated.
2. A name-only update preserves email and bio. `{"bio": null}` clears
   the bio. Empty or overlong names and overlong bios return `422`.
3. A valid email change requires the correct current password and
   clears the old verification state. An invalid or duplicate address
   returns `422`. Sending the same normalized email does not require
   password confirmation.
4. An update containing a protected key such as `id`, `role`, or
   `permissions` returns `422` and saves none of the submitted changes.
   This also applies when the protected key's value is `null`.
5. A request with no editable fields returns `422`. A `PATCH` without
   valid CSRF protection returns `419`.
   - An account without genre preferences returns `"favorite_genres": []`.
6. A genre-only update replaces the selection and preserves the name,
   email, and bio. Omitting the field preserves the selection, and
   sending `[]` clears it.
7. Unknown or duplicate genres, a non-list value, `null`, or more than
   five selections return `422` and save none of the update.
## Profile endpoint verification (AB#32)

Run from backend:

    php artisan test --filter=ProfileTest
    php artisan test

ProfileTest uses RefreshDatabase and the in-memory SQLite database in
phpunit.xml. It covers retrieval, partial updates, genre selection,
normalization, invalid input, protected fields, email-change password
confirmation, authentication, and CSRF enforcement.

The new migration adds bio and favorite_genres to users. After reviewing
this change, run php artisan migrate against your configured development
database before manually using the endpoints. This migration has not
been applied to the local development database during implementation.

The endpoints return only id, name, email, bio, and favorite_genres.
They always select the authenticated account. Profile completion
 enforcement remains a separate task (AB#33).

## Profile completion (AB#33)

A profile is complete when its trimmed name is a nonempty string of at
most 255 characters and its email is a valid RFC address of at most
255 characters. Bio, favorite genres, and email verification are not
completion requirements. New accounts with valid registration data
normally pass immediately.

GET and PATCH /api/v1/me/profile keep the existing data object and add:

```json
{
  "meta": {
    "profile_complete": false,
    "incomplete_fields": ["name"]
  }
}
```

Both values are computed from the current account. They cannot be set by
the client and are recalculated after an update. A complete profile has
profile_complete: true and incomplete_fields: [].

### Applying the requirement

Apply authentication first and the registered profile.complete alias
second, only to application features that require completion:

```php
Route::middleware(['auth:sanctum', 'profile.complete'])->group(function () {
    // Register movie browsing and other restricted application routes here.
});
```

For an unauthenticated request, return 401. An authenticated user with an
incomplete profile receives 403 with this stable response:

```json
{
  "message": "Complete your profile before accessing this feature.",
  "code": "PROFILE_INCOMPLETE",
  "incomplete_fields": ["name"]
}
```

The frontend should use the code and field list to guide the user to
profile editing. This response is different from 401 (sign-in needed)
and 422 (invalid submitted update).

Do not put profile retrieval/update, login, registration, logout, or
authentication-state restoration behind profile.complete. Incomplete
accounts must be able to sign in, inspect and repair their profile, and
sign out. Keep operational health and the temporary TMDB diagnostic
endpoint outside this requirement.

The updated product flow requires an account for movie browsing.
The production movie endpoints have not yet been implemented in this
branch. Their owners must attach auth:sanctum and profile.complete when
adding them; registering this alias alone does not secure a route.
No production movie route or frontend access guard is added by AB#33.

### Verification

Run from backend:

    php artisan test --filter=ProfileCompletionTest
    php artisan test

Completion tests use the isolated SQLite test database and test-only
feature routes. They verify required-field validation, optional-field
independence, guest rejection, incomplete-profile rejection, profile
repair and immediate access recovery, client override rejection, and
continued access to public health/diagnostic endpoints.
