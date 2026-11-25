<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\MovieNight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CrossFeatureAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_ownership_and_membership_do_not_grant_access_to_other_features(): void
    {
        $member = User::factory()->create();
        $night = MovieNight::factory()->scheduled()->create();
        $otherNight = MovieNight::factory()->scheduled()->create([
            'organizer_id' => $night->organizer_id,
        ]);
        $list = $night->organizer->movieLists()->create(['name' => 'Private shortlist']);
        $movie = Movie::factory()->create();
        $nightUrl = "/api/v1/movie-nights/{$night->id}";
        $otherNightUrl = "/api/v1/movie-nights/{$otherNight->id}";

        $this->signIn($night->organizer);
        $this->postJson("$nightUrl/invitations", ['invitee_id' => $member->id])->assertCreated();
        $candidateId = $this->postJson("$otherNightUrl/candidates", ['movie_id' => $movie->id])
            ->assertCreated()->json('data.candidates.0.id');
        $this->postJson("$otherNightUrl/messages", ['text' => 'Private discussion'])->assertCreated();

        $this->signIn($member);
        $this->patchJson("$nightUrl/rsvp", ['status' => 'accepted'])->assertOk();
        $this->getJson("$nightUrl/messages")->assertOk();
        $this->getJson($otherNightUrl)->assertNotFound();
        $this->patchJson("$otherNightUrl/rsvp", ['status' => 'accepted'])->assertNotFound();
        $this->putJson("$otherNightUrl/vote", ['candidate_id' => $candidateId])->assertNotFound();
        $this->getJson("$otherNightUrl/messages")->assertNotFound();
        $this->postJson("$otherNightUrl/messages", ['text' => 'Unauthorized'])->assertNotFound();
        $this->getJson("/api/v1/lists/{$list->id}")->assertNotFound();
        $this->patchJson("/api/v1/admin/users/{$night->organizer_id}/status", ['status' => 'disabled'])
            ->assertForbidden();

        $this->assertDatabaseCount('movie_night_participants', 1);
        $this->assertDatabaseCount('movie_night_votes', 0);
        $this->assertDatabaseCount('movie_night_messages', 1);
        $this->assertNull($night->organizer->fresh()->disabled_at);
    }

    public function test_disabling_an_account_blocks_its_existing_feature_permissions(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $night = MovieNight::factory()->scheduled()->create(['organizer_id' => $owner->id]);
        $list = $owner->movieLists()->create(['name' => 'Private shortlist']);
        $messagesUrl = "/api/v1/movie-nights/{$night->id}/messages";
        $listUrl = "/api/v1/lists/{$list->id}";

        $this->signIn($owner);
        $this->getJson($listUrl)->assertOk();
        $this->getJson($messagesUrl)->assertOk();
        $this->getJson('/api/v1/feed')->assertOk();

        $this->signIn($admin);
        $this->patchJson("/api/v1/admin/users/{$owner->id}/status", ['status' => 'disabled'])
            ->assertOk();

        $this->signIn($owner->fresh());
        $this->getJson($listUrl)->assertUnauthorized();
        $this->getJson($messagesUrl)->assertUnauthorized();
        $this->postJson($messagesUrl, ['text' => 'Disabled organizer'])->assertUnauthorized();
        $this->getJson('/api/v1/feed')->assertUnauthorized();

        $this->assertDatabaseCount('movie_night_messages', 0);
    }

    private function signIn(User $user): void
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
    }
}
