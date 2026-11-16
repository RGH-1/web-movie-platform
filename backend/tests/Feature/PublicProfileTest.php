<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_read_only_the_requested_public_identity(): void
    {
        User::factory()->create(['name' => 'Other user']);
        $author = User::factory()->create(['name' => 'Review author']);

        $this->getJson("/api/v1/users/{$author->id}/profile")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => (string) $author->id,
                    'name' => 'Review author',
                    'statistics' => ['reviews_count' => 0, 'public_lists_count' => 0],
                    'followers_count' => 0,
                    'following_count' => 0,
                    'reviews' => [],
                    'public_lists' => [],
                ],
            ]);

        $this->getJson('/api/v1/me/profile')->assertUnauthorized();
    }

    public function test_invalid_and_missing_public_profiles_return_404(): void
    {
        foreach (['0', '01', 'abc', '9223372036854775808', '999999'] as $id) {
            $this->getJson("/api/v1/users/{$id}/profile")->assertNotFound();
        }
    }

    public function test_only_public_activity_is_returned_even_to_the_owner(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $movie = Movie::factory()->create();
        $visible = $author->reviews()->make(['rating' => 4, 'review_text' => 'Public review']);
        $visible->movie()->associate($movie);
        $visible->save();
        foreach ([$author, $other] as $user) {
            $review = $user->reviews()->make(['rating' => 1, 'review_text' => 'Secret review']);
            $review->movie()->associate(Movie::factory()->create());
            $review->moderation_status = Review::STATUS_HIDDEN;
            $review->save();
        }
        $inactive = $author->reviews()->make(['rating' => 2]);
        $inactive->movie()->associate(Movie::factory()->create(['deactivated_at' => now()]));
        $inactive->save();
        $public = $author->movieLists()->create(['name' => 'Public list', 'is_public' => true]);
        $public->movies()->attach($movie);
        $author->movieLists()->create(['name' => 'Private list', 'is_public' => false]);
        $other->movieLists()->create(['name' => 'Other list', 'is_public' => true]);
        $author->movieStatuses()->create(['movie_id' => $movie->id, 'watched_at' => now()]);

        $guest = $this->getJson("/api/v1/users/{$author->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.statistics', ['reviews_count' => 1, 'public_lists_count' => 1])
            ->assertJsonCount(1, 'data.reviews')
            ->assertJsonPath('data.reviews.0.id', (string) $visible->id)
            ->assertJsonPath('data.public_lists', [[
                'id' => (string) $public->id, 'name' => 'Public list',
                'description' => null, 'movies_count' => 1,
            ]])
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.movie_statuses')
            ->assertJsonMissingPath('data.reviews.0.moderation_status')
            ->assertJsonMissing(['name' => 'Private list'])
            ->assertJsonMissing(['review_text' => 'Secret review']);

        $this->actingAs($author)->getJson("/api/v1/users/{$author->id}/profile")
            ->assertExactJson($guest->json());
    }

    public function test_previews_are_bounded_but_counts_cover_all_public_content(): void
    {
        $author = User::factory()->create();
        for ($i = 0; $i < 12; $i++) {
            $author->movieLists()->create(['name' => "List $i", 'is_public' => true]);
            $review = $author->reviews()->make(['rating' => 3]);
            $review->movie()->associate(Movie::factory()->create());
            $review->save();
        }

        $this->getJson("/api/v1/users/{$author->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.statistics', ['reviews_count' => 12, 'public_lists_count' => 12])
            ->assertJsonCount(10, 'data.reviews')
            ->assertJsonCount(10, 'data.public_lists')
            ->assertJsonPath('data.public_lists.0.name', 'List 11');
    }

    public function test_follow_counts_use_distinct_incoming_and_outgoing_relationships(): void
    {
        $author = User::factory()->create();
        $follower = User::factory()->create();
        $mutual = User::factory()->create();
        $target = User::factory()->create();
        $follower->following()->syncWithoutDetaching([$author->id]);
        $follower->following()->syncWithoutDetaching([$author->id]);
        $mutual->following()->attach($author->id);
        $author->following()->attach([$mutual->id, $target->id]);
        // Defensive filtering also excludes malformed self relationships.
        $author->following()->attach($author->id);
        $target->following()->attach($follower->id);

        $this->getJson("/api/v1/users/{$author->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.followers_count', 2)
            ->assertJsonPath('data.following_count', 2)
            ->assertJsonMissingPath('data.followers')
            ->assertJsonMissingPath('data.following');

        $author->following()->detach($target->id);
        $follower->delete();

        $this->getJson("/api/v1/users/{$author->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.followers_count', 1)
            ->assertJsonPath('data.following_count', 1);
        $this->assertDatabaseMissing('user_follows', ['follower_id' => $follower->id]);
        $this->assertDatabaseMissing('user_follows', ['followed_id' => $follower->id]);
    }
}
