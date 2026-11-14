# Custom movie lists schema — AB#90

## Tables and relationships

`movie_lists` has an ID, required owner `user_id`, required `name` (up to 255 characters),
nullable text `description`, `is_public` (default false), and Laravel timestamps.
Indexes cover owner lookup and public lists ordered by creation time.
`MovieList` permits mass assignment of name, description, and visibility only.
Create lists through `User::movieLists()` to assign ownership on the server.
Visibility casts to boolean and list IDs serialize as strings.

`movie_list_items` is the timestamped pivot between a list and canonical `movies.id`.
Its composite primary key `(movie_list_id, movie_id)` prevents duplicate membership.
A separate movie index supports inverse lookup and the movie foreign key.
No extra item model is needed: `MovieList::movies()` and `Movie::movieLists()`
use Eloquent's timestamped many-to-many relationship.
`MovieList::user()` returns the owner.

These task-required names replace the original `custom_lists`/`list_movies`
placeholders. There are no duplicate legacy tables. The ERD and catalog test are updated.

## Deletion and privacy

Permanent user deletion cascades to their lists and list entries. Deleting one list
removes its entries. Neither action deletes catalog movies or another user's lists.
Logging out does not delete users, lists, or entries.
A movie referenced by a list cannot be hard-deleted; catalog deactivation retains entries.

Private by default is a storage default, not access control. AB#91 implements ownership,
visibility enforcement, input validation, and active-movie checks at the API boundary.
AB#90 adds no routes or UI and does not change logout behavior.

## Automated verification

From `backend`, run:

```powershell
php artisan test --filter=MovieListSchemaTest
php artisan test
```

The default suite uses SQLite in memory. Tests verify real relationships, defaults,
canonical membership, uniqueness, missing-reference rejection, and cascading/restrictive
foreign keys. Verification: all 9 schema tests passed on both SQLite and an isolated MySQL database
(28 assertions each). The full SQLite backend suite passed: 186 passed, 2 existing
MySQL-only tests skipped. The temporary MySQL database was removed afterward;
the development database was not migrated.

## Manual development database check

1. Back up any data you need and run `php artisan migrate:status` from `backend`.
2. Run `php artisan migrate` to add the pending tables. Never use `migrate:fresh`
   on a database containing data you want to preserve.
3. Inspect `movie_lists` and `movie_list_items` in your database tool. Check the
   private default, composite primary key, indexes, and cascade/restrict foreign keys.
4. There is no list page yet. API and browser workflows follow in AB#91–94.

Migration rollback drops both list tables and their data; use it only deliberately.
