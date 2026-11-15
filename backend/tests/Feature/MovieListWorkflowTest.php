<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovieListWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_then_privatizing_a_list_revokes_discovery_and_direct_access(): void
    {
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        $movie = Movie::factory()->create();
        $this->actingAs($owner);
        $id = $this->postJson('/api/v1/lists', ['name' => 'Visibility lifecycle'])->assertCreated()->json('data.id');
        $url = '/api/v1/lists/'.$id;
        $this->postJson($url.'/movies', ['movie_id' => $movie->id])->assertOk();

        $this->actingAs($visitor)->getJson($url)->assertNotFound();
        $this->getJson('/api/v1/lists/public')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($owner)->patchJson($url, ['is_public' => true])->assertOk();
        $this->actingAs($visitor)->getJson($url)->assertOk()
            ->assertJsonPath('data.movies.0.local_movie_id', $movie->id)
            ->assertJsonMissingPath('data.author.email');
        $this->getJson('/api/v1/lists/public')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/me/lists')->assertOk()->assertJsonPath('data', []);

        $this->actingAs($owner)->patchJson($url, ['is_public' => false])->assertOk();
        $this->actingAs($visitor)->getJson($url)->assertNotFound();
        $this->getJson('/api/v1/lists/public')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($url.'/movies', ['movie_id' => $movie->id])->assertNotFound();
        $this->deleteJson($url.'/movies/'.$movie->id)->assertNotFound();
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('data.movie_count', 1);
    }

    public function test_deleting_one_list_preserves_shared_catalog_movie_and_another_owners_membership(): void
    {
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $movie = Movie::factory()->tmdb(157336)->create();
        $this->actingAs($firstOwner);
        $first = $this->postJson('/api/v1/lists', ['name' => 'First'])->assertCreated()->json('data.id');
        $firstUrl = '/api/v1/lists/'.$first;
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($firstUrl.'/movies', ['movie_id' => $movie->id])->assertOk()->assertJsonPath('data.movie_count', 1);
        }
        $this->actingAs($secondOwner);
        $second = $this->postJson('/api/v1/lists', ['name' => 'Second'])->assertCreated()->json('data.id');
        $secondUrl = '/api/v1/lists/'.$second;
        $this->postJson($secondUrl.'/movies', ['movie_id' => $movie->id])->assertOk();
        $this->actingAs($firstOwner)->deleteJson($firstUrl)->assertNoContent();
        $this->assertModelExists($movie);
        $this->assertDatabaseCount('movie_list_items', 1);
        $this->actingAs($secondOwner)->getJson($secondUrl)->assertOk()
            ->assertJsonPath('data.movie_count', 1)->assertJsonPath('data.movies.0.local_movie_id', $movie->id);
        $this->deleteJson($secondUrl.'/movies/'.$movie->id)->assertNoContent();
        $this->getJson($secondUrl)->assertOk()->assertJsonPath('data.movie_count', 0);
        $this->assertModelExists($movie);
    }

    public function test_invalid_edits_and_memberships_leave_saved_metadata_and_movies_unchanged(): void
    {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $this->actingAs($user);
        $id = $this->postJson('/api/v1/lists', ['name' => 'Keep me', 'description' => 'Original'])->assertCreated()->json('data.id');
        $url = '/api/v1/lists/'.$id;
        $this->postJson($url.'/movies', ['movie_id' => $movie->id])->assertOk();
        foreach ([['name' => '   '], ['description' => str_repeat('x', 5001)],
            ['is_public' => null], ['user_id' => User::factory()->create()->id]] as $invalid) {
            $this->patchJson($url, $invalid)->assertUnprocessable();
        }
        $this->postJson($url.'/movies', ['movie_id' => '01'])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('data.name', 'Keep me')
            ->assertJsonPath('data.description', 'Original')->assertJsonPath('data.is_public', false)
            ->assertJsonPath('data.author.id', (string) $user->id)->assertJsonPath('data.movie_count', 1);
        $this->patchJson($url, ['description' => null])->assertOk()->assertJsonPath('data.description', null);
        $this->getJson($url)->assertOk()->assertJsonPath('data.name', 'Keep me')->assertJsonPath('data.movie_count', 1);
    }
}
