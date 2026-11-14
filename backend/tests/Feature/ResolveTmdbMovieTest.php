<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveTmdbMovieTest extends TestCase
{
    use RefreshDatabase;

    private const RESOLVE = '/api/v1/movies/tmdb/42/resolve';
    private const DETAILS = '/api/v1/movies/42';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::preventStrayRequests();
    }

    public function test_first_review_imports_once_and_prefills_on_reload(): void
    {
        Http::fake(['*/movie/42*' => Http::response([
            'id' => 42, 'title' => 'Provider movie',
        ])]);

        $author = User::factory()->create();
        $this->actingAs($author, 'web');

        $this->getJson(self::DETAILS)->assertOk()
            ->assertJsonPath('data.local_movie_id', null);
        $this->assertDatabaseCount('movies', 0);

        $id = $this->postJson(self::RESOLVE)->assertOk()
            ->assertJsonPath('data.tmdb_id', 42)
            ->assertJsonPath('data.source', 'tmdb')
            ->json('data.local_movie_id');

        $this->assertIsString($id);
        $this->postJson(self::RESOLVE)->assertOk()
            ->assertJsonPath('data.local_movie_id', $id);

        // Details and the first resolve contact TMDB. The second resolve reuses the row.
        Http::assertSentCount(2);
        $this->assertDatabaseCount('movies', 1);

        $reviewId = $this->postJson("/api/v1/catalog/movies/{$id}/reviews", [
            'rating' => 4,
            'review_text' => 'First review.',
        ])->assertCreated()->assertJsonPath('data.movie_id', $id)->json('data.id');

        $this->getJson(self::DETAILS)->assertOk()
            ->assertJsonPath('data.local_movie_id', $id)
            ->assertJsonPath('data.viewer_review.id', $reviewId)
            ->assertJsonPath('data.viewer_review.rating', 4)
            ->assertJsonPath('data.viewer_review.review_text', 'First review.');

        $this->patchJson("/api/v1/reviews/{$reviewId}", ['rating' => 5])
            ->assertOk()->assertJsonPath('data.rating', 5);
        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_guests_and_incomplete_profiles_cannot_resolve(): void
    {
        Http::fake();

        $this->postJson(self::RESOLVE)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['name' => ' ']), 'web')
            ->postJson(self::RESOLVE)->assertForbidden()
            ->assertJsonPath('code', 'PROFILE_INCOMPLETE');

        Http::assertNothingSent();
        $this->assertDatabaseCount('movies', 0);
    }

    public function test_invalid_and_deactivated_mappings_are_rejected_without_importing(): void
    {
        Http::fake();
        $movie = Movie::factory()->tmdb(42)->create(['deactivated_at' => now()]);
        $this->actingAs(User::factory()->create(), 'web');

        $this->postJson('/api/v1/movies/tmdb/0/resolve')
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_MOVIE_ID');
        $this->postJson(self::RESOLVE)->assertNotFound()
            ->assertJsonPath('code', 'MOVIE_NOT_FOUND');
        $this->postJson("/api/v1/catalog/movies/{$movie->id}/reviews", ['rating' => 4])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('movies', 1);
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_provider_errors_and_invalid_payloads_create_no_rows(): void
    {
        Http::fakeSequence()
            ->push([], 429, ['Retry-After' => '2'])
            ->push([], 404)
            ->push([], 500)
            ->push(['id' => 43, 'title' => 'Wrong movie']);

        $this->actingAs(User::factory()->create(), 'web');

        $this->postJson(self::RESOLVE)->assertStatus(503)
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('code', 'MOVIE_RESOLVE_RATE_LIMITED');
        $this->postJson(self::RESOLVE)->assertNotFound();
        $this->postJson(self::RESOLVE)->assertStatus(502);
        $this->postJson(self::RESOLVE)->assertStatus(502);

        $this->assertDatabaseCount('movies', 0);
    }
}