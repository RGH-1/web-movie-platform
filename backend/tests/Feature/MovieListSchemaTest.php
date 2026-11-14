<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\MovieList;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MovieListSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_owns_multiple_lists_with_private_defaults_and_optional_description(): void
    {
        $user = User::factory()->create();
        $private = $user->movieLists()->create(['name' => 'Weekend']);
        $public = $user->movieLists()->create([
            'name' => 'Favorites', 'description' => 'Films to share', 'is_public' => true,
        ]);

        $this->assertCount(2, $user->movieLists);
        $this->assertTrue($private->user->is($user));
        $this->assertFalse($private->fresh()->is_public);
        $this->assertNull($private->fresh()->description);
        $this->assertTrue($public->fresh()->is_public);
        $this->assertIsString($private->fresh()->id);
        $this->assertNotNull($private->created_at);
        // The database default also applies to writers outside Eloquent.
        $id = DB::table('movie_lists')->insertGetId(['user_id' => $user->id, 'name' => 'Database default']);
        $this->assertFalse(MovieList::findOrFail($id)->is_public);
    }

    public function test_canonical_movie_can_appear_in_different_lists_with_timestamped_memberships(): void
    {
        $first = User::factory()->create()->movieLists()->create(['name' => 'First']);
        $second = User::factory()->create()->movieLists()->create(['name' => 'Second']);
        $movie = Movie::factory()->tmdb(157336)->create();
        $first->movies()->attach($movie->id);
        $second->movies()->attach($movie->id);

        $this->assertTrue($first->movies->first()->is($movie));
        $this->assertCount(2, $movie->movieLists);
        $this->assertNotNull($first->movies->first()->pivot->created_at);
        $this->assertNotNull($first->movies->first()->pivot->updated_at);
        $this->assertDatabaseHas('movie_list_items', ['movie_list_id' => $first->id, 'movie_id' => $movie->id]);
    }

    public function test_database_rejects_duplicate_membership(): void
    {
        $list = User::factory()->create()->movieLists()->create(['name' => 'Favorites']);
        $movie = Movie::factory()->create();
        $list->movies()->attach($movie->id);
        $this->expectException(QueryException::class);
        $list->movies()->attach($movie->id);
    }

    public function test_deleting_list_cascades_items_and_preserves_movies_and_other_lists(): void
    {
        $user = User::factory()->create();
        $first = $user->movieLists()->create(['name' => 'First']);
        $second = $user->movieLists()->create(['name' => 'Second']);
        $movie = Movie::factory()->create();
        $first->movies()->attach($movie->id);
        $second->movies()->attach($movie->id);
        $first->delete();

        $this->assertModelMissing($first);
        $this->assertModelExists($movie);
        $this->assertDatabaseMissing('movie_list_items', ['movie_list_id' => $first->id]);
        $this->assertCount(1, $second->movies);
    }

    public function test_referenced_movie_cannot_be_hard_deleted(): void
    {
        $list = User::factory()->create()->movieLists()->create(['name' => 'Favorites']);
        $movie = Movie::factory()->create();
        $list->movies()->attach($movie->id);
        $this->expectException(QueryException::class);
        $movie->delete();
    }

    public function test_database_rejects_missing_owner(): void
    {
        $this->expectException(QueryException::class);
        DB::table('movie_lists')->insert(['user_id' => 999999, 'name' => 'Orphan']);
    }

    public function test_database_rejects_missing_movie(): void
    {
        $list = User::factory()->create()->movieLists()->create(['name' => 'Favorites']);
        $this->expectException(QueryException::class);
        $list->movies()->attach(999999);
    }

    public function test_database_rejects_missing_list(): void
    {
        $movie = Movie::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('movie_list_items')->insert(['movie_list_id' => 999999, 'movie_id' => $movie->id]);
    }
    public function test_deleting_owner_removes_their_lists_and_items_but_preserves_movies_and_other_owners(): void
    {
        $owner = User::factory()->create();
        $first = $owner->movieLists()->create(['name' => 'First']);
        $second = $owner->movieLists()->create(['name' => 'Second', 'is_public' => true]);
        $other = User::factory()->create()->movieLists()->create(['name' => 'Other owner']);
        $movie = Movie::factory()->create();
        foreach ([$first, $second, $other] as $list) {
            $list->movies()->attach($movie->id);
        }

        $owner->delete();

        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
        $this->assertModelExists($other);
        $this->assertModelExists($movie);
        $this->assertDatabaseCount('movie_list_items', 1);
        $this->assertTrue($other->movies->first()->is($movie));
    }

}
