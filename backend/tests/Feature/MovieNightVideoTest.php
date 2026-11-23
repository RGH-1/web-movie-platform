<?php

namespace Tests\Feature;

use App\Models\MovieNight;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MovieNightVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_finalized_organizer_can_assign_and_members_can_stream_ranges(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('video-assets/demo.mp4', '0123456789');
        $night = MovieNight::factory()->finalized()->create();
        $participant = User::factory()->create(); $outsider = User::factory()->create();
        $night->participants()->attach($participant);
        $asset = VideoAsset::create(['title' => 'Licensed demo', 'file_path' => 'video-assets/demo.mp4', 'duration_seconds' => 10]);
        $url = "/api/v1/movie-nights/{$night->id}";

        $this->getJson("$url/video")->assertUnauthorized();
        $this->signIn($outsider);
        $this->getJson("$url/video")->assertNotFound();
        $this->putJson("$url/video", ['video_asset_id' => $asset->id])->assertNotFound();

        $this->signIn($night->organizer);
        $this->getJson("$url/video-assets")->assertOk()->assertJsonPath('data.0.id', (string) $asset->id);
        $this->putJson("$url/video", ['video_asset_id' => $asset->id])->assertOk()->assertJsonPath('data.video.title', 'Licensed demo');

        $this->signIn($participant);
        $this->getJson("$url/video")->assertOk()->assertJsonPath('data.video.stream_url', "$url/video/stream");
        $this->withHeader('Range', 'bytes=2-5')->get("$url/video/stream")
            ->assertStatus(206)->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Range', 'bytes 2-5/10');
    }

    public function test_assignment_requires_finalization_and_missing_files_return_not_found(): void
    {
        Storage::fake('local');
        $asset = VideoAsset::create(['title' => 'Missing demo', 'file_path' => 'video-assets/missing.mp4', 'duration_seconds' => 5]);
        $night = MovieNight::factory()->scheduled()->create();
        $this->signIn($night->organizer);
        $this->putJson("/api/v1/movie-nights/{$night->id}/video", ['video_asset_id' => $asset->id])->assertForbidden();
    }

    private function signIn(User $user): void { Auth::forgetGuards(); $this->actingAs($user, 'web'); }
}
