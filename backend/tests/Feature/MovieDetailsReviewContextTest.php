<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MovieDetailsReviewContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_return_the_catalog_id_and_only_the_viewers_review(): void
    {
        $local = Movie::factory()->create();
        $providerId = (int) $local->id;
        $movie = Movie::factory()->tmdb($providerId)->create();
        $author = User::factory()->create();
        $other = User::factory()->create();

        $review = $author->reviews()->make(['rating' => 4, 'review_text' => 'My draft.']);
        $review->movie()->associate($movie);
        $review->moderation_status = Review::STATUS_HIDDEN;
        $review->save();

        $this->fakeMovie($providerId);

        $this->actingAs($author, 'web')
            ->getJson("/api/v1/movies/{$providerId}")
            ->assertOk()
            ->assertJsonPath('data.tmdb_id', $providerId)
            ->assertJsonPath('data.local_movie_id', $movie->id)
            ->assertJsonPath('data.viewer_review.id', (string) $review->id)
            ->assertJsonPath('data.viewer_review.rating', 4)
            ->assertJsonPath('data.viewer_review.review_text', 'My draft.')
            ->assertJsonMissingPath('data.viewer_review.moderation_status');

        Auth::forgetGuards();
        $this->actingAs($other, 'web')
            ->getJson("/api/v1/movies/{$providerId}")
            ->assertOk()
            ->assertJsonPath('data.local_movie_id', $movie->id)
            ->assertJsonPath('data.viewer_review', null);
    }

    public function test_inactive_catalog_mapping_leaves_details_available(): void
    {
        Movie::factory()->tmdb(597)->create(['deactivated_at' => now()]);
        $this->fakeMovie(597);

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/movies/597')
            ->assertOk()
            ->assertJsonPath('data.local_movie_id', null)
            ->assertJsonPath('data.viewer_review', null);

        $this->assertDatabaseCount('movies', 1);
        $this->assertDatabaseCount('reviews', 0);
    }

    private function fakeMovie(int $tmdbId): void
    {
        $baseUrl = 'https://api.themoviedb.org/3';
        config([
            'services.tmdb.base_url' => $baseUrl,
            'services.tmdb.token' => 'fake-test-token',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            "{$baseUrl}/movie/{$tmdbId}*" => Http::response([
                'id' => $tmdbId,
                'title' => 'Test movie',
            ]),
        ]);
    }
}