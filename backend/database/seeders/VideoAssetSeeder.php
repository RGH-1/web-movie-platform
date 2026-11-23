<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VideoAssetSeeder extends Seeder
{
    public function run(): void
    {
        $path = 'videos/watch-room-demo.mp4';

        // Ensure the demo MP4 exists in Laravel's private storage.
        if (!Storage::disk('local')->exists($path)) {
            throw new RuntimeException(
                "Demo video not found: {$path}. Download the MP4 before seeding."
            );
        }

        // Insert the video or update its metadata if it already exists.
        DB::table('video_assets')->upsert(
            [
                [
                    'title' => 'Watch Room Demo',
                    'file_path' => $path,
                    'duration_seconds' => 60,
                    'thumbnail_path' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ],
            ['file_path'],
            ['title', 'duration_seconds', 'thumbnail_path', 'updated_at']
        );

        $this->command->info('Watch Room Demo video registered successfully!');
    }
}
