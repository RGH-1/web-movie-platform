<?php

namespace Tests\Feature;

use App\Events\MovieNightMessageCreated;
use App\Models\MovieNight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MovieNightBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_channel_uses_the_room_view_policy(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
        $night = MovieNight::factory()->scheduled()->create();
        $participant = User::factory()->create();
        $outsider = User::factory()->create();
        $night->participants()->attach($participant);
        $endpoint = '/api/v1/broadcasting/auth';
        $subscription = [
            'socket_id' => '123.456',
            'channel_name' => "private-movie-nights.{$night->id}",
        ];

        $this->postJson($endpoint, $subscription)->assertUnauthorized();
        $this->actingAs($outsider, 'web')->postJson($endpoint, $subscription)->assertForbidden();

        Auth::forgetGuards();
        $this->actingAs($night->organizer, 'web')->postJson($endpoint, $subscription)
            ->assertOk()->assertJsonStructure(['auth']);

        Auth::forgetGuards();
        $this->actingAs($participant, 'web')->postJson($endpoint, $subscription)
            ->assertOk()->assertJsonStructure(['auth']);

        $night->participants()->detach($participant);
        $this->postJson($endpoint, $subscription)->assertForbidden();
    }

    public function test_persisting_a_message_dispatches_a_room_notification(): void
    {
        Event::fake([MovieNightMessageCreated::class]);
        $night = MovieNight::factory()->scheduled()->create();
        $endpoint = "/api/v1/movie-nights/{$night->id}/messages";
        $this->actingAs($night->organizer, 'web');

        $this->postJson($endpoint, ['text' => ''])->assertUnprocessable();
        Event::assertNotDispatched(MovieNightMessageCreated::class);

        $this->postJson($endpoint, ['text' => 'Hello'])->assertCreated();
        $this->assertDatabaseHas('movie_night_messages', ['movie_night_id' => $night->id, 'text' => 'Hello']);
        Event::assertDispatchedOnce(MovieNightMessageCreated::class);
        Event::assertDispatched(function (MovieNightMessageCreated $event) use ($night): bool {
            return $event->broadcastOn()->name === "private-movie-nights.{$night->id}"
                && $event->broadcastAs() === 'message.created'
                && $event->broadcastWith() === [];
        });
    }
}
