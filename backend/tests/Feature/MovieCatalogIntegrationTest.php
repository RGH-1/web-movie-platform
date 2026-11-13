<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Services\MovieCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MovieCatalogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3', 'services.tmdb.token' => 'test']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(), 'web');
    }

    public function test_search_does_not_import_and_returns_existing_identity(): void
    {
        $existing = Movie::factory()->tmdb(42)->create();
        Http::fake(['*/search/movie*' => Http::response([
            'results' => [['id' => 42, 'title' => 'Existing'], ['id' => 43, 'title' => 'New']],
            'total_pages' => 1, 'total_results' => 2,
        ])]);
        $this->getJson('/api/v1/movies/search?query=movie')->assertOk()
            ->assertJsonPath('data.0.local_movie_id', $existing->id)
            ->assertJsonPath('data.1.local_movie_id', null)
            ->assertJsonPath('data.1.source', 'tmdb');
        $this->assertDatabaseCount('movies', 1);
    }

    public function test_details_create_one_canonical_record_and_preserve_overrides(): void
    {
        Http::fake(['*/movie/42*' => Http::response([
            'id' => 42, 'title' => 'Provider title', 'runtime' => 120,
            'release_date' => '2020-02-30', 'genres' => [['id' => 18, 'name' => 'Drama']],
        ])]);
        $first = $this->getJson('/api/v1/movies/42')->assertOk()
            ->assertJsonPath('data.source', 'tmdb')
            ->assertJsonPath('data.runtime_minutes', 120)
            ->assertJsonPath('data.release_date', null);
        $id = $first->json('data.local_movie_id');
        $this->assertNotNull($id);
        Movie::findOrFail($id)->update(['title' => 'Admin title', 'metadata_overrides' => ['title']]);
        $this->getJson('/api/v1/movies/42')->assertOk()
            ->assertJsonPath('data.local_movie_id', $id)
            ->assertJsonPath('data.title', 'Admin title');
        $resolved = app(MovieCatalogService::class)->resolveTmdbId(42);
        $this->assertSame($id, $resolved->id);
        $this->assertDatabaseCount('movies', 1);
        $this->assertSame(['title'], $resolved->metadata_overrides);
    }

    public function test_local_details_need_no_external_configuration_or_request(): void
    {
        config(['services.tmdb.token' => null]);
        $movie = Movie::factory()->create(['title' => 'Local film']);
        $this->getJson('/api/v1/catalog/movies/'.$movie->id)->assertOk()
            ->assertJsonPath('data.local_movie_id', $movie->id)
            ->assertJsonPath('data.tmdb_id', null)
            ->assertJsonPath('data.source', 'local')
            ->assertJsonMissingPath('data.metadata_overrides');
        $this->getJson('/api/v1/catalog/movies/999')->assertNotFound();
        $this->getJson('/api/v1/catalog/movies/invalid')->assertUnprocessable();
        $movie->update(['deactivated_at' => now()]);
        $this->getJson('/api/v1/catalog/movies/'.$movie->id)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_invalid_and_failed_external_movies_are_never_persisted(): void
    {
        Http::fakeSequence()->push([], 404)->push(['id' => 43, 'title' => 'Wrong identity'])
            ->push([], 500)->push(['id' => 42, 'title' => str_repeat('x', 256)]);
        $this->getJson('/api/v1/movies/42')->assertNotFound();
        $this->getJson('/api/v1/movies/42')->assertStatus(502);
        $this->getJson('/api/v1/movies/42')->assertStatus(502);
        $this->getJson('/api/v1/movies/42')->assertStatus(502);
        $this->getJson('/api/v1/movies/0')->assertUnprocessable();
        $this->assertDatabaseCount('movies', 0);
    }
}
