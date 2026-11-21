<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowingFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_filters_activities_and_paginates_across_tied_timestamps(): void
    {
        $this->freezeSecond();
        $viewer = User::factory()->create();
        $author = User::factory()->create();
        $outsider = User::factory()->create();
        $viewer->following()->attach($author);
        $outsider->following()->attach($viewer);

        $rating = $this->reviewFor($author);
        $review = $this->reviewFor($author, ['review_text' => 'Worth watching']);
        $this->reviewFor($author, ['moderation_status' => Review::STATUS_HIDDEN]);
        $this->reviewFor($outsider);
        $this->reviewFor($viewer);
        $inactiveReview = $this->reviewFor($author);
        $inactiveReview->movie->update(['deactivated_at' => now()]);
        $list = $author->movieLists()->create(['name' => 'Shared', 'is_public' => true]);
        $author->movieLists()->create(['name' => 'Private']);
        $outsider->movieLists()->create(['name' => 'Unrelated', 'is_public' => true]);

        $this->actingAs($viewer)->get('/api/v1/feed?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.type', 'public_list')
            ->assertJsonPath('data.0.target_id', $list->id)
            ->assertJsonPath('data.0.metadata.name', 'Shared')
            ->assertJsonPath('data.1.type', 'rating')
            ->assertJsonPath('data.1.target_id', (string) $rating->id)
            ->assertJsonPath('data.1.metadata.rating', 4);

        $this->get('/api/v1/feed?per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.type', 'review')
            ->assertJsonPath('data.0.target_id', (string) $review->id)
            ->assertJsonPath('data.0.metadata.movie_id', $review->movie_id)
            ->assertJsonPath('data.0.author', ['id' => (string) $author->id, 'name' => $author->name]);

        $viewer->following()->detach($author);
        $this->get('/api/v1/feed')->assertOk()->assertJsonPath('data', []);
    }

    public function test_feed_uses_publication_time_and_removes_lists_that_go_private(): void
    {
        $this->freezeSecond();
        $viewer = User::factory()->create();
        $author = User::factory()->create();
        $viewer->following()->attach($author);
        $list = $author->movieLists()->create(['name' => 'Draft']);
        $legacy = $author->movieLists()->create(['name' => 'Legacy', 'is_public' => true]);
        $legacy->forceFill(['published_at' => null])->save();

        $this->travel(1)->hours();
        $this->reviewFor($author);
        $this->actingAs($author)->patchJson("/api/v1/lists/{$list->id}", ['is_public' => true])->assertOk();
        $publishedAt = now()->toIso8601String();
        $this->travel(1)->hours();
        $this->actingAs($author)->patchJson("/api/v1/lists/{$list->id}", ['name' => 'Renamed'])->assertOk();

        $this->actingAs($viewer)->get('/api/v1/feed')->assertOk()
            ->assertJsonPath('data.0.type', 'public_list')
            ->assertJsonPath('data.0.timestamp', $publishedAt)
            ->assertJsonPath('data.0.metadata.name', 'Renamed')
            ->assertJsonPath('data.2.target_id', $legacy->id)
            ->assertJsonPath('data.2.timestamp', $legacy->created_at->toIso8601String());

        $this->actingAs($author)->patchJson("/api/v1/lists/{$list->id}", ['is_public' => false])->assertOk();
        $this->actingAs($viewer)->get('/api/v1/feed')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.type', 'rating');
    }

    public function test_author_activity_preference_applies_before_pagination(): void
    {
        $viewer = User::factory()->create();
        $author = User::factory()->create();
        $other = User::factory()->create();
        $viewer->following()->attach([$author->id, $other->id]);

        $remaining = $this->reviewFor($other);
        $this->travel(1)->minutes();
        $this->reviewFor($author);
        $this->reviewFor($author, ['review_text' => 'Review']);
        $author->movieLists()->create(['name' => 'Public', 'is_public' => true]);

        $this->actingAs($viewer)->getJson('/api/v1/feed?per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.author.id', (string) $author->id);

        $this->actingAs($author)->patchJson('/api/v1/me/profile', ['share_activity' => false])
            ->assertOk()->assertJsonPath('data.share_activity', false);

        $this->actingAs($viewer)->getJson('/api/v1/feed?per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_id', (string) $remaining->id)
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 1, 'total' => 1]);
        $this->getJson('/api/v1/feed?per_page=1&page=2')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 1);

        $this->actingAs($author)->patchJson('/api/v1/me/profile', ['share_activity' => true])
            ->assertOk()->assertJsonPath('data.share_activity', true);
        $this->actingAs($viewer)->getJson('/api/v1/feed?per_page=1&page=4')
            ->assertOk()->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.target_id', (string) $remaining->id);
    }

    public function test_feed_returns_json_authentication_and_validation_errors(): void
    {
        $this->get('/api/v1/feed')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->actingAs(User::factory()->create())->get('/api/v1/feed?page=0&per_page=51')
            ->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
    }

    private function reviewFor(User $author, array $attributes = []): Review
    {
        $review = Movie::factory()->create()->reviews()->make(['rating' => 4]);
        $review->user()->associate($author);
        $review->forceFill($attributes)->save();

        return $review;
    }
}
