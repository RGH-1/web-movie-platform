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

The only editable profile fields are `name`, `email`, and `bio`.
Treat `id`, roles, permissions, `email_verified_at`, `remember_token`,
`created_at`, and `updated_at` as system-managed. Handle password
changes in a separate account flow.

Reject protected and unknown keys in the update body, including keys
whose values are `null`. Persist only the validated `name`, `email`,
and `bio` fields. The `current_password` field is a request-only
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