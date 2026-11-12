<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_read_visible_reviews_and_movie_wide_statistics(): void
    {
        $movie = Movie::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->seedReview($movie, 1);
        }

        $newest = $this->seedReview($movie, 5);
        $this->seedReview($movie, 5, Review::STATUS_HIDDEN);
        $this->seedReview(Movie::factory()->create(), 5);

        $this->getJson($this->movieUrl($movie))
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.id', (string) $newest->id)
            ->assertJsonPath('data.0.movie_id', $movie->id)
            ->assertJsonPath('data.0.rating', 5)
            ->assertJsonPath('data.0.author', [
                'id' => (string) $newest->user_id,
                'name' => $newest->user->name,
            ])
            ->assertJsonStructure(['data' => [['created_at', 'updated_at']]])
            ->assertJsonMissingPath('data.0.moderated_by')
            ->assertJsonMissingPath('data.0.moderation_reason')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 11)
            ->assertJsonPath('meta.rating_count', 11)
            ->assertJsonPath('meta.rating_average', 1.36);

        $this->getJson($this->movieUrl($movie) . '?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.rating_count', 11)
            ->assertJsonPath('meta.rating_average', 1.36);
    }

    public function test_movie_with_only_hidden_reviews_has_no_public_ratings(): void
    {
        $movie = Movie::factory()->create();
        $this->seedReview($movie, 5, Review::STATUS_HIDDEN);

        $this->getJson($this->movieUrl($movie))
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.rating_count', 0)
            ->assertJsonPath('meta.rating_average', null);
    }

    public function test_create_uses_the_authenticated_author_and_canonical_movie_id(): void
    {
        $author = User::factory()->create();
        $movie = Movie::factory()->create();
        Movie::factory()->tmdb((int) $movie->id)->create();

        $this->actingAs($author, 'web')
            ->postJson($this->movieUrl($movie), ['rating' => 4])
            ->assertCreated()
            ->assertJsonPath('data.movie_id', $movie->id)
            ->assertJsonPath('data.author.id', (string) $author->id)
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.review_text', null);

        $this->postJson($this->movieUrl($movie), ['rating' => 2])
            ->assertStatus(409)
            ->assertJsonPath('code', 'REVIEW_ALREADY_EXISTS');

        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseHas('reviews', [
            'user_id' => $author->id,
            'movie_id' => $movie->id,
            'rating' => 4,
            'moderation_status' => Review::STATUS_VISIBLE,
        ]);
    }

    public function test_guests_cannot_write_and_incomplete_profiles_cannot_submit(): void
    {
        $movie = Movie::factory()->create();
        $review = $this->seedReview($movie, 4);

        $this->postJson($this->movieUrl($movie), ['rating' => 4])->assertUnauthorized();
        $this->patchJson($this->reviewUrl($review), ['rating' => 3])->assertUnauthorized();
        $this->deleteJson($this->reviewUrl($review))->assertUnauthorized();

        $this->actingAs(User::factory()->create(['name' => ' ']), 'web')
            ->postJson($this->movieUrl($movie), ['rating' => 4])
            ->assertForbidden()
            ->assertJsonPath('code', 'PROFILE_INCOMPLETE');

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_non_owner_cannot_edit_or_delete_a_review(): void
    {
        $review = $this->seedReview(Movie::factory()->create(), 4);
        $this->actingAs(User::factory()->create(), 'web');

        $this->patchJson($this->reviewUrl($review), ['rating' => 99])->assertForbidden();
        $this->deleteJson($this->reviewUrl($review))->assertForbidden();

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'rating' => 4]);
    }

    public function test_owner_can_patch_and_delete_without_clearing_moderation(): void
    {
        $movie = Movie::factory()->create();
        $review = $this->seedReview($movie, 4, Review::STATUS_HIDDEN);

        $this->actingAs($review->user, 'web')
            ->patchJson($this->reviewUrl($review), ['review_text' => '  Edited text.  '])
            ->assertOk()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.review_text', 'Edited text.');

        $this->patchJson($this->reviewUrl($review), ['review_text' => null])
            ->assertOk()
            ->assertJsonPath('data.review_text', null);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'moderation_status' => Review::STATUS_HIDDEN,
        ]);

        $movie->update(['deactivated_at' => now()]);
        $this->deleteJson($this->reviewUrl($review))->assertNoContent();

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_invalid_inputs_and_forged_fields_are_rejected(): void
    {
        $movie = Movie::factory()->create();
        $review = $this->seedReview($movie, 4);
        $this->actingAs($review->user, 'web');

        foreach ([null, 0, 6, 2.5, '4', true] as $rating) {
            $this->postJson($this->movieUrl($movie), ['rating' => $rating])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('rating');
        }

        $this->postJson($this->movieUrl($movie), ['review_text' => 'Rating is required.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rating');

        $this->patchJson($this->reviewUrl($review), ['review_text' => str_repeat('x', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_text');

        $this->patchJson($this->reviewUrl($review), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review');

        $forged = [
            'rating' => 3,
            'user_id' => User::factory()->create()->id,
            'movie_id' => Movie::factory()->create()->id,
            'moderation_status' => null,
        ];

        foreach ([
            ['POST', $this->movieUrl($movie)],
            ['PATCH', $this->reviewUrl($review)],
        ] as [$method, $url]) {
            $this->json($method, $url, $forged)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['user_id', 'movie_id', 'moderation_status']);
        }

        $this->getJson($this->movieUrl($movie) . '?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $review->user_id,
            'movie_id' => $movie->id,
            'rating' => 4,
        ]);
    }

    public function test_missing_inactive_and_invalid_catalog_resources_return_404(): void
    {
        $inactive = Movie::factory()->tmdb(157336)->create([
            'deactivated_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(), 'web');

        foreach ([$inactive->id, '157336', '0', '9223372036854775808'] as $id) {
            $url = "/api/v1/catalog/movies/{$id}/reviews";
            $this->getJson($url)->assertNotFound();
            $this->postJson($url, ['rating' => 4])->assertNotFound();
        }

        $this->patchJson('/api/v1/reviews/999999', ['rating' => 4])->assertNotFound();
        $this->deleteJson('/api/v1/reviews/9223372036854775808')->assertNotFound();
    }

    private function seedReview(
        Movie $movie,
        int $rating,
        string $status = Review::STATUS_VISIBLE,
    ): Review {
        $review = $movie->reviews()->make(['rating' => $rating]);
        $review->user()->associate(User::factory()->create());
        $review->moderation_status = $status;
        $review->save();

        return $review;
    }

    private function movieUrl(Movie $movie): string
    {
        return "/api/v1/catalog/movies/{$movie->id}/reviews";
    }

    private function reviewUrl(Review $review): string
    {
        return "/api/v1/reviews/{$review->id}";
    }
}