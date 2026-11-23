
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_assets', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_path')->unique();
            $table->unsignedInteger('duration_seconds');
            $table->string('thumbnail_path')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            // Preserve existing SQLite CHECK constraints.
            DB::statement(
                'ALTER TABLE movie_nights
                 ADD COLUMN video_asset_id INTEGER NULL
                 REFERENCES video_assets(id) ON DELETE SET NULL'
            );

            DB::statement(
                'CREATE INDEX movie_nights_video_asset_id_index
                 ON movie_nights (video_asset_id)'
            );
        } else {
            Schema::table('movie_nights', function (Blueprint $table) {
                $table->foreignId('video_asset_id')
                    ->nullable()
                    ->after('winning_movie_id')
                    ->constrained('video_assets')
                    ->nullOnDelete();

                $table->index('video_asset_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'DROP INDEX IF EXISTS movie_nights_video_asset_id_index'
            );

            DB::statement(
                'ALTER TABLE movie_nights DROP COLUMN video_asset_id'
            );
        } else {
            Schema::table('movie_nights', function (Blueprint $table) {
                $table->dropConstrainedForeignId('video_asset_id');
            });
        }

        Schema::dropIfExists('video_assets');
    }
};
