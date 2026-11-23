<?php

namespace App\Http\Controllers;

use App\Models\MovieNight;
use App\Models\VideoAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MovieNightVideoController extends Controller
{
    public function assets(Request $request, MovieNight $movieNight): JsonResponse
    {
        Gate::authorize('assignVideo', $movieNight);
        return response()->json(['data' => VideoAsset::query()->orderBy('title')->get()->map(fn (VideoAsset $asset) => $this->asset($asset))])->header('Cache-Control', 'private, no-store');
    }

    public function assign(Request $request, MovieNight $movieNight): JsonResponse
    {
        $data = $request->validate(['video_asset_id' => ['required', 'integer', Rule::exists('video_assets', 'id')]]);
        $night = DB::transaction(function () use ($request, $movieNight, $data) {
            $night = MovieNight::query()->lockForUpdate()->findOrFail($movieNight->id);
            Gate::forUser($request->user())->authorize('assignVideo', $night);
            DB::table('movie_nights')->where('id', $night->id)->update(['video_asset_id' => $data['video_asset_id'], 'updated_at' => now()]);
            return $night->fresh('videoAsset');
        });
        return response()->json(['data' => $this->selected($night)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, MovieNight $movieNight): JsonResponse
    {
        Gate::authorize('view', $movieNight);
        $movieNight->load('videoAsset');
        abort_unless($movieNight->videoAsset, 404, 'No video has been assigned.');
        return response()->json(['data' => $this->selected($movieNight)])->header('Cache-Control', 'private, no-store');
    }

    public function stream(Request $request, MovieNight $movieNight)
    {
        Gate::authorize('view', $movieNight);
        $asset = $movieNight->videoAsset;
        abort_unless($asset && Storage::disk('local')->exists($asset->file_path), 404, 'Video file is unavailable.');
        $path = Storage::disk('local')->path($asset->file_path);
        $size = filesize($path); $start = 0; $end = $size - 1; $status = 200;
        if (preg_match('/bytes=(\d*)-(\d*)/', (string) $request->header('Range'), $range)) {
            $start = $range[1] === '' ? max(0, $size - (int) $range[2]) : (int) $range[1];
            $end = $range[2] === '' ? $end : min((int) $range[2], $end);
            abort_if($start > $end || $start >= $size, 416);
            $status = 206;
        }
        $length = $end - $start + 1;
        return response()->stream(function () use ($path, $start, $length) { $handle = fopen($path, 'rb'); fseek($handle, $start); $remaining = $length; while ($remaining > 0 && !feof($handle)) { $chunk = fread($handle, min(8192, $remaining)); echo $chunk; $remaining -= strlen($chunk); } fclose($handle); }, $status, ['Content-Type' => 'video/mp4', 'Content-Length' => $length, 'Accept-Ranges' => 'bytes', 'Content-Range' => $status === 206 ? "bytes $start-$end/$size" : null, 'Cache-Control' => 'private, no-store']);
    }

    private function asset(VideoAsset $asset): array { return ['id' => (string) $asset->id, 'title' => $asset->title, 'duration_seconds' => $asset->duration_seconds, 'thumbnail_url' => null]; }
    private function selected(MovieNight $night): array { return ['movie_night_id' => (string) $night->id, 'video' => $this->asset($night->videoAsset) + ['stream_url' => "/api/v1/movie-nights/{$night->id}/video/stream"]]; }
}
