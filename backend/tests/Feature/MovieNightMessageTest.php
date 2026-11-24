<?php

namespace Tests\Feature;

use App\Models\MovieNight;
use App\Models\MovieNightInvitation;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class MovieNightMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_and_accepted_participant_share_persistent_history(): void
    {
        $video = VideoAsset::create([
            'title' => 'Demo',
            'file_path' => 'video-assets/demo.mp4',
            'duration_seconds' => 90,
        ]);
        $night = MovieNight::factory()->finalized()->create(['video_asset_id' => $video->id]);
        $participant = User::factory()->create();
        $night->participants()->attach($participant);
        $url = "/api/v1/movie-nights/{$night->id}/messages";

        $this->actingAs($night->organizer, 'web');
        $first = $this->postJson($url, ['text' => 'Welcome'])
            ->assertCreated()
            ->assertJsonPath('data.video_timestamp_seconds', null)
            ->assertJsonPath('data.is_spoiler', false)
            ->json('data');

        Auth::forgetGuards();
        $this->actingAs($participant, 'web');
        $second = $this->postJson($url, [
            'text' => 'The ending',
            'video_timestamp_seconds' => 89.5,
            'is_spoiler' => true,
            'sender_id' => $night->organizer_id,
        ])->assertCreated()
            ->assertJsonPath('data.sender_id', (string) $participant->id)
            ->assertJsonPath('data.video_timestamp_seconds', 89.5)
            ->assertJsonPath('data.is_spoiler', true)
            ->assertJsonStructure(['data' => ['id', 'created_at', 'updated_at']])
            ->json('data');

        $this->getJson($url)->assertOk()->assertJsonPath('data', [$second, $first]);

        $night->participants()->detach($participant);
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['text' => 'Removed'])->assertNotFound();
        $this->assertDatabaseCount('movie_night_messages', 2);
    }

    public function test_pending_invitees_and_guests_cannot_read_or_send(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $invitee = User::factory()->create();
        $night->invitations()->save((new MovieNightInvitation)->forceFill([
            'invitee_id' => $invitee->id,
            'inviter_id' => $night->organizer_id,
        ]));
        $url = "/api/v1/movie-nights/{$night->id}/messages";

        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, ['text' => 'Guest'])->assertUnauthorized();
        $this->actingAs($invitee, 'web');
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['text' => 'Pending'])->assertNotFound();
    }

    public function test_invalid_fields_are_rejected_and_send_attempts_are_rate_limited(): void
    {
        $video = VideoAsset::create([
            'title' => 'Demo',
            'file_path' => 'video-assets/demo.mp4',
            'duration_seconds' => 90,
        ]);
        $night = MovieNight::factory()->finalized()->create(['video_asset_id' => $video->id]);
        $url = "/api/v1/movie-nights/{$night->id}/messages";
        $this->actingAs($night->organizer, 'web');

        foreach ([
            ['text', '   '],
            ['text', str_repeat('a', 1001)],
            ['video_timestamp_seconds', -1],
            ['video_timestamp_seconds', 90.1],
            ['video_timestamp_seconds', 'tomorrow'],
            ['is_spoiler', 'maybe'],
        ] as [$field, $value]) {
            $this->postJson($url, [$field => $value] + ['text' => 'Valid'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('movie_night_messages', 0);
        for ($attempt = 0; $attempt < 14; $attempt++) {
            $this->postJson($url, ['text' => 'Valid'])->assertCreated();
        }
        $this->postJson($url, ['text' => 'Over the limit'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('movie_night_messages', 14);
        $this->getJson($url)->assertOk()->assertJsonCount(14, 'data');
    }
}
