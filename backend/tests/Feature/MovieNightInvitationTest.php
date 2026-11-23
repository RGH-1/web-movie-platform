<?php

namespace Tests\Feature;

use App\Models\MovieNight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class MovieNightInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitations_and_rsvps_persist_and_change_membership(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $invitee = User::factory()->create();
        $secondInvitee = User::factory()->create();
        $url = "/api/v1/movie-nights/{$night->id}";

        $this->signIn($night->organizer);
        $this->postJson("$url/invitations", ['invitee_id' => $invitee->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.inviter_id', (string) $night->organizer_id);
        $this->postJson("$url/invitations", ['invitee_id' => $secondInvitee->id])->assertCreated();
        $this->postJson("$url/invitations", ['invitee_id' => $invitee->id])
            ->assertUnprocessable()->assertJsonValidationErrors('invitee_id');

        $this->signIn($invitee);
        $this->getJson($url)->assertNotFound();
        $this->getJson("$url/invitations")->assertOk()
            ->assertJsonCount(1, 'data.pending')
            ->assertJsonPath('data.pending.0.invitee.id', (string) $invitee->id);
        $this->patchJson("$url/rsvp", ['status' => 'pending'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("$url/rsvp", ['status' => 'accepted', 'invitee_id' => $secondInvitee->id])
            ->assertUnprocessable()->assertJsonValidationErrors('invitee_id');
        $this->patchJson("$url/rsvp", ['status' => 'accepted'])->assertOk();
        $this->patchJson("$url/rsvp", ['status' => 'accepted'])->assertOk();
        $this->getJson($url)->assertOk();
        $this->assertDatabaseCount('movie_night_participants', 1);

        $this->signIn($secondInvitee);
        $this->patchJson("$url/rsvp", ['status' => 'declined'])->assertOk();

        $this->signIn($night->organizer);
        $this->getJson("$url/invitations")->assertOk()
            ->assertJsonCount(0, 'data.pending')
            ->assertJsonPath('data.accepted.0.invitee.id', (string) $invitee->id)
            ->assertJsonPath('data.declined.0.invitee.id', (string) $secondInvitee->id);

        $this->signIn($invitee);
        $this->patchJson("$url/rsvp", ['status' => 'declined'])
            ->assertOk()->assertJsonPath('data.status', 'declined');
        $this->getJson($url)->assertNotFound();
        $this->assertDatabaseCount('movie_night_participants', 0);
        $this->assertDatabaseHas('movie_night_invitations', [
            'movie_night_id' => $night->id,
            'invitee_id' => $invitee->id,
            'status' => 'declined',
        ]);
    }

    public function test_access_is_private_and_cancelled_invitations_are_read_only(): void
    {
        $night = MovieNight::factory()->create();
        $invitee = User::factory()->create();
        $outsider = User::factory()->create();
        $url = "/api/v1/movie-nights/{$night->id}";

        $this->getJson("$url/invitations")->assertUnauthorized();

        $this->signIn($night->organizer);
        $this->postJson("$url/invitations", ['invitee_id' => $invitee->id])->assertCreated();
        $this->patchJson("$url/rsvp", ['status' => 'accepted'])->assertForbidden();

        $this->signIn($invitee);
        $this->getJson("$url/invitations")->assertNotFound();
        $this->patchJson("$url/rsvp", ['status' => 'accepted'])->assertNotFound();

        $this->signIn($night->organizer);
        $this->patchJson($url, ['status' => 'scheduled'])->assertOk();

        $this->signIn($outsider);
        $this->getJson("$url/invitations")->assertNotFound();
        $this->patchJson("$url/rsvp", ['status' => 'accepted'])->assertNotFound();

        $night->participants()->attach($outsider);
        $this->getJson("$url/invitations")->assertNotFound();
        $this->postJson("$url/invitations", ['invitee_id' => $invitee->id])->assertForbidden();
        $this->patchJson("$url/rsvp", ['status' => 'accepted'])->assertForbidden();

        $this->signIn($night->organizer);
        $this->postJson("$url/cancel")->assertOk();
        $this->postJson("$url/invitations", ['invitee_id' => $outsider->id])->assertForbidden();
        $this->getJson("$url/invitations")->assertOk()->assertJsonCount(1, 'data.pending');

        $this->signIn($invitee);
        $this->getJson("$url/invitations")->assertOk()->assertJsonCount(1, 'data.pending');
        $this->patchJson("$url/rsvp", ['status' => 'accepted'])->assertForbidden();
        $this->getJson($url)->assertNotFound();
    }

    private function signIn(User $user): void
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
    }
}
