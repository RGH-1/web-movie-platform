<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewModerationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_statistics_follow_visible_review_creation_edit_and_deletion(): void
    {
        $movie = Movie::factory()->create();
        $other = User::factory()->create();
        $author = User::factory()->create();
        $url = "/api/v1/catalog/movies/{$movie->id}/reviews";

        $this->makeReview($other, $movie, 2);
        $this->actingAs($author, 'web');

        $id = $this->postJson($url, ['rating' => 1])
            ->assertCreated()
            ->assertJsonPath('data.review_text', null)
            ->json('data.id');

        $this->getJson($url)->assertOk()
            ->assertJsonPath('meta.rating_count', 2)
            ->assertJsonPath('meta.rating_average', 1.5);

        $this->patchJson("/api/v1/reviews/{$id}", ['rating' => 5])
            ->assertOk()
            ->assertJsonPath('data.rating', 5);

        $this->getJson($url)->assertOk()
            ->assertJsonPath('meta.rating_count', 2)
            ->assertJsonPath('meta.rating_average', 3.5);

        $this->deleteJson("/api/v1/reviews/{$id}")->assertNoContent();

        $this->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rating', 2)
            ->assertJsonPath('meta.rating_count', 1)
            ->assertJsonPath('meta.rating_average', 2);
    }

    public function test_hidden_review_blocks_duplicates_and_stays_hidden_after_edit(): void
    {
        $movie = Movie::factory()->create();
        $author = User::factory()->create();
        $moderator = User::factory()->create();
        $visible = $this->makeReview(User::factory()->create(), $movie, 2);
        $hidden = $this->makeReview($author, $movie, 1);
        $url = "/api/v1/catalog/movies/{$movie->id}/reviews";

        $hidden->moderation_status = Review::STATUS_HIDDEN;
        $hidden->moderator()->associate($moderator);
        $hidden->moderated_at = now()->startOfSecond();
        $hidden->moderation_reason = 'Moderation test fixture';
        $hidden->save();

        $moderatedAt = $hidden->moderated_at->toDateTimeString();

        $this->actingAs($author, 'web')
            ->postJson($url, ['rating' => 5])
            ->assertStatus(409)
            ->assertJsonPath('code', 'REVIEW_ALREADY_EXISTS');

        $this->patchJson("/api/v1/reviews/{$hidden->id}", [
            'rating' => 5,
            'review_text' => 'Edited while hidden.',
        ])->assertOk()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.review_text', 'Edited while hidden.');

        $this->assertDatabaseCount('reviews', 2);
        $this->assertDatabaseHas('reviews', [
            'id' => $hidden->id,
            'rating' => 5,
            'moderation_status' => Review::STATUS_HIDDEN,
            'moderated_by' => $moderator->id,
            'moderated_at' => $moderatedAt,
            'moderation_reason' => 'Moderation test fixture',
        ]);

        $this->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $visible->id)
            ->assertJsonPath('meta.rating_count', 1)
            ->assertJsonPath('meta.rating_average', 2);
    }

    private function makeReview(User $author, Movie $movie, int $rating): Review
    {
        $review = $author->reviews()->make(['rating' => $rating]);
        $review->movie()->associate($movie);
        $review->save();

        return $review;
    }
}