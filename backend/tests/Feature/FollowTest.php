<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_actions_are_idempotent_and_ignore_spoofed_identity(): void
    {
        [$actor, $target, $other] = User::factory()->count(3)->create()->all();
        $url = "/api/v1/users/{$target->id}/follow";
        $this->actingAs($actor, 'web');
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($url, ['follower_id' => $other->id])->assertOk()
                ->assertJsonPath('data.followers_count', 1)->assertJsonPath('data.is_following', true);
        }
        $this->assertDatabaseCount('follows', 1);
        $this->assertDatabaseHas('follows', ['follower_id' => $actor->id, 'followed_id' => $target->id]);
        $this->assertSame(1, $actor->following()->count());
        for ($i = 0; $i < 2; $i++) {
            $this->deleteJson($url)->assertOk()->assertJsonPath('data.followers_count', 0)->assertJsonPath('data.is_following', false);
        }
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_guests_cannot_write_and_self_follow_is_rejected(): void
    {
        $user = User::factory()->create();
        $url = "/api/v1/users/{$user->id}/follow";
        $this->postJson($url)->assertUnauthorized();
        $this->deleteJson($url)->assertUnauthorized();
        $this->actingAs($user, 'web')->postJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_public_lists_have_correct_pagination_counts_and_public_fields(): void
    {
        [$target, $one, $two] = User::factory()->count(3)->create()->all();
        $target->followers()->attach([$one->id, $two->id]);
        $url = "/api/v1/users/{$target->id}/followers?per_page=1";
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', (string) $one->id)->assertJsonPath('data.0.is_following', false)
            ->assertJsonPath('data.0.following_count', 1)
            ->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.pivot');
        $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.0.id', (string) $two->id);
        $this->actingAs($one, 'web')->getJson("/api/v1/users/{$one->id}/following")
            ->assertOk()->assertJsonPath('data.0.id', (string) $target->id)
            ->assertJsonPath('data.0.is_following', true)->assertJsonPath('data.0.followers_count', 2);
        $this->getJson($url.'&page=0')->assertUnprocessable();
        $this->getJson("/api/v1/users/{$target->id}/following?per_page=51")->assertUnprocessable();
        $this->getJson('/api/v1/users/999999/followers')->assertNotFound();
        $this->postJson('/api/v1/users/999999/follow')->assertNotFound();
    }

    public function test_database_enforces_unique_pairs(): void
    {
        [$one, $two] = User::factory()->count(2)->create()->all();
        $one->following()->attach($two->id);
        $this->expectException(UniqueConstraintViolationException::class);
        $one->following()->attach($two->id);
    }
}
