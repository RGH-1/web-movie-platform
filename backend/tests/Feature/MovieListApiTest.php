<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\MovieList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovieListApiTest extends TestCase
{
    use RefreshDatabase;

    private function listFor(User $user, bool $public = false): MovieList
    {
        return $user->movieLists()->create(['name' => 'Favorites', 'is_public' => $public]);
    }

    public function test_every_list_endpoint_requires_login(): void
    {
        foreach ([['get', '/me/lists'], ['get', '/lists/public'], ['get', '/lists/1'],
            ['post', '/lists'], ['patch', '/lists/1'], ['delete', '/lists/1'],
            ['post', '/lists/1/movies'], ['delete', '/lists/1/movies/1']] as [$method, $path]) {
            $this->json(strtoupper($method), '/api/v1'.$path)->assertUnauthorized();
        }
    }

    public function test_owner_can_create_update_and_delete_without_losing_catalog_movies(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $response = $this->postJson('/api/v1/lists', ['name' => 'Weekend']);
        $response->assertCreated()->assertJsonPath('data.is_public', false)
            ->assertJsonPath('data.author.id', (string) $user->id)->assertJsonPath('data.movie_count', 0);
        $id = $response->json('data.id');
        $this->assertIsString($id);
        $movie = Movie::factory()->create();
        MovieList::findOrFail($id)->movies()->attach($movie->id);
        $this->patchJson('/api/v1/lists/'.$id, ['name' => 'Shared', 'description' => 'Hello', 'is_public' => true])
            ->assertOk()->assertJsonPath('data.name', 'Shared')->assertJsonPath('data.is_public', true);
        $this->deleteJson('/api/v1/lists/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('movie_lists', ['id' => $id]);
        $this->assertDatabaseCount('movie_list_items', 0);
        $this->assertModelExists($movie);
    }

    public function test_public_discovery_is_paginated_and_private_lists_do_not_leak(): void
    {
        $owner = User::factory()->create();
        $private = $this->listFor($owner);
        $public = $this->listFor($owner, true);
        $public->movies()->attach(Movie::factory()->create()->id);
        $this->listFor($owner, true);
        $this->actingAs(User::factory()->create());
        $response = $this->getJson('/api/v1/lists/public?per_page=1');
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/lists/public?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $public->id)
            ->assertJsonPath('data.0.movie_count', 1)->assertJsonMissingPath('data.0.author.email');
        $this->getJson('/api/v1/lists/'.$private->id)->assertNotFound();
        $this->getJson('/api/v1/lists/'.$public->id)->assertOk()->assertJsonCount(1, 'data.movies');
        $this->getJson('/api/v1/me/lists')->assertOk()->assertJsonPath('data', []);
        $this->actingAs($owner)->getJson('/api/v1/me/lists')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/lists/'.$private->id)->assertOk();
    }

    public function test_non_owner_cannot_mutate_even_a_public_list(): void
    {
        $list = $this->listFor(User::factory()->create(), true);
        $movie = Movie::factory()->create();
        $list->movies()->attach($movie->id);
        $this->actingAs(User::factory()->create());
        $url = '/api/v1/lists/'.$list->id;
        $this->patchJson($url, ['name' => 'Stolen'])->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $this->postJson($url.'/movies', ['movie_id' => $movie->id])->assertNotFound();
        $this->deleteJson($url.'/movies/'.$movie->id)->assertNotFound();
        $this->assertSame('Favorites', $list->fresh()->name);
        $this->assertDatabaseCount('movie_list_items', 1);
    }

    public function test_membership_is_canonical_idempotent_and_removable_after_deactivation(): void
    {
        $user = User::factory()->create();
        $list = $this->listFor($user);
        $movie = Movie::factory()->tmdb(157336)->create();
        $this->actingAs($user);
        $url = '/api/v1/lists/'.$list->id;
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($url.'/movies', ['movie_id' => $movie->id])->assertOk()->assertJsonPath('data.movie_count', 1);
        }
        $this->assertDatabaseCount('movie_list_items', 1);
        $this->postJson($url.'/movies', ['movie_id' => '157336'])->assertNotFound();
        $movie->update(['deactivated_at' => now()]);
        $this->postJson($url.'/movies', ['movie_id' => $movie->id])->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonPath('data.movies.0.local_movie_id', $movie->id)
            ->assertJsonPath('data.movies.0.tmdb_id', 157336)->assertJsonPath('data.movies.0.is_active', false);
        $this->deleteJson($url.'/movies/'.$movie->id)->assertNoContent();
        $this->deleteJson($url.'/movies/'.$movie->id)->assertNoContent();
        $this->assertModelExists($movie);
    }

    public function test_list_movie_summaries_are_paginated_and_local_metadata_can_be_null(): void
    {
        $user = User::factory()->create();
        $list = $this->listFor($user);
        foreach (Movie::factory()->count(3)->create() as $movie) {
            $list->movies()->attach($movie->id);
        }
        $this->actingAs($user)->getJson('/api/v1/lists/'.$list->id.'?per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data.movies')->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.movie_count', 3)->assertJsonPath('data.movies.0.tmdb_id', null)
            ->assertJsonPath('data.movies.0.release_date', null);
    }

    public function test_invalid_fields_and_forged_ownership_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([[], ['name' => '  '], ['name' => str_repeat('x', 256)],
            ['name' => 'List', 'description' => str_repeat('x', 5001)],
            ['name' => 'List', 'is_public' => 'yes'], ['name' => 'List', 'user_id' => 123]] as $data) {
            $this->postJson('/api/v1/lists', $data)->assertUnprocessable();
        }
        $this->assertDatabaseCount('movie_lists', 0);
        $list = $this->listFor(User::first());
        foreach ([null, 1, '0', '01', '1.5', '-1'] as $value) {
            $this->postJson('/api/v1/lists/'.$list->id.'/movies', ['movie_id' => $value])->assertUnprocessable();
        }
        $this->getJson('/api/v1/lists/public?per_page=51')->assertUnprocessable();
        $this->getJson('/api/v1/me/lists?page=0')->assertUnprocessable();
        foreach (['01', 'abc', '9223372036854775808'] as $id) {
            $this->getJson('/api/v1/lists/'.$id)->assertNotFound();
        }
    }

    public function test_incomplete_profile_can_read_but_cannot_write(): void
    {
        $user = User::factory()->create(['name' => '']);
        $list = $this->listFor($user);
        $this->actingAs($user);
        $this->getJson('/api/v1/me/lists')->assertOk();
        $this->getJson('/api/v1/lists/public')->assertOk();
        $this->getJson('/api/v1/lists/'.$list->id)->assertOk();
        $this->postJson('/api/v1/lists', ['name' => 'Blocked'])->assertForbidden();
        $this->patchJson('/api/v1/lists/'.$list->id, ['is_public' => true])->assertForbidden();
        $this->deleteJson('/api/v1/lists/'.$list->id)->assertForbidden();
        $this->postJson('/api/v1/lists/'.$list->id.'/movies', ['movie_id' => '1'])->assertForbidden();
        $this->deleteJson('/api/v1/lists/'.$list->id.'/movies/1')->assertForbidden();
    }
}
