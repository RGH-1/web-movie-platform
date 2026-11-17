<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_following_pages_are_complete_and_only_expose_public_fields(): void
    {
        $actor = User::factory()->create();
        $targets = User::factory()->count(3)->create();
        $actor->following()->attach($targets->modelKeys());

        foreach ($targets as $index => $target) {
            $this->getJson("/api/v1/users/{$actor->id}/following?per_page=1&page=".($index + 1))
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3)
                ->assertJsonPath('data.0', [
                    'id' => (string) $target->id, 'name' => $target->name,
                    'followers_count' => 1, 'following_count' => 0, 'is_following' => false,
                ]);
        }
        $this->getJson("/api/v1/users/{$actor->id}/following?per_page=1&page=4")
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 3);
    }

    public static function profileViewers(): array
    {
        return ['guest' => ['guest'], 'follower' => ['follower'], 'other user' => ['other'], 'owner' => ['owner']];
    }

    #[DataProvider('profileViewers')]
    public function test_follow_state_belongs_to_the_viewer_on_profiles_and_both_lists(string $viewerType): void
    {
        [$viewer, $other, $target, $hub] = User::factory()->count(4)->create()->all();
        $viewer->following()->attach($target->id);
        $target->following()->attach($hub->id);
        $hub->following()->attach($target->id);
        $paths = [
            ["/api/v1/users/{$target->id}/profile", 'data.is_following'],
            ["/api/v1/users/{$hub->id}/followers", 'data.0.is_following'],
            ["/api/v1/users/{$hub->id}/following", 'data.0.is_following'],
        ];
        $user = match ($viewerType) {
            'follower' => $viewer,
            'other' => $other,
            'owner' => $target,
            default => null,
        };
        if ($user) {
            $this->actingAs($user, 'web');
        }
        foreach ($paths as [$url, $path]) {
            $this->getJson($url)->assertOk()->assertJsonPath($path, $viewerType === 'follower');
        }
    }

    public function test_account_deletion_cascades_both_directions_and_preserves_other_pairs(): void
    {
        [$deleted, $one, $two] = User::factory()->count(3)->create()->all();
        $deleted->following()->attach([$one->id, $two->id]);
        $one->following()->attach([$deleted->id, $two->id]);
        $two->following()->attach($deleted->id);
        $deleted->delete();

        $this->assertDatabaseMissing('follows', ['follower_id' => $deleted->id]);
        $this->assertDatabaseMissing('follows', ['followed_id' => $deleted->id]);
        $this->assertDatabaseCount('follows', 1);
        $this->assertDatabaseHas('follows', ['follower_id' => $one->id, 'followed_id' => $two->id]);
        $this->getJson("/api/v1/users/{$one->id}/profile")->assertOk()
            ->assertJsonPath('data.followers_count', 0)->assertJsonPath('data.following_count', 1);
        $this->getJson("/api/v1/users/{$two->id}/followers")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', (string) $one->id);
        $this->getJson("/api/v1/users/{$deleted->id}/profile")->assertNotFound();
    }

    public function test_rejected_changes_preserve_existing_relationships(): void
    {
        [$actor, $target] = User::factory()->count(2)->create()->all();
        $actor->following()->attach($target->id);
        $url = "/api/v1/users/{$target->id}/follow";
        $this->postJson($url)->assertUnauthorized();
        $this->deleteJson($url)->assertUnauthorized();
        $this->actingAs($actor, 'web');
        $this->postJson("/api/v1/users/{$actor->id}/follow")->assertUnprocessable();
        $this->deleteJson("/api/v1/users/{$actor->id}/follow")->assertUnprocessable();
        $this->deleteJson('/api/v1/users/999999/follow')->assertNotFound();
        $this->assertDatabaseCount('follows', 1);
        $this->assertDatabaseHas('follows', ['follower_id' => $actor->id, 'followed_id' => $target->id]);
    }
}
