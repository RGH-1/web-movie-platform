<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmdb_id')->nullable()->unique();
            $table->string('source', 8);
            $table->string('title');
            $table->text('overview')->nullable();
            $table->date('release_date')->nullable();
            $table->unsignedInteger('runtime_minutes')->nullable();
            $table->json('genres');
            $table->text('poster_url')->nullable();
            $table->text('backdrop_url')->nullable();
            $table->string('director')->nullable();
            $table->json('cast');
            $table->decimal('vote_average', 5, 3)->nullable();
            $table->json('trailer')->nullable();
            $table->json('metadata_overrides');
            $table->timestamp('tmdb_synced_at')->nullable();
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->timestamps();
        });

        // Production MySQL/MariaDB enforces invariants even for direct SQL writes.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE movies ADD CONSTRAINT movies_source_identity_check CHECK (
                (source = 'tmdb' AND tmdb_id IS NOT NULL AND tmdb_id BETWEEN 1 AND 2147483647)
                OR (source = 'local' AND tmdb_id IS NULL AND vote_average IS NULL AND tmdb_synced_at IS NULL)
            )");
            DB::statement('ALTER TABLE movies ADD CONSTRAINT movies_rating_check CHECK (
                vote_average IS NULL OR vote_average BETWEEN 0 AND 10
            )');
            DB::statement('ALTER TABLE movies ADD CONSTRAINT movies_runtime_check CHECK (
                runtime_minutes IS NULL OR runtime_minutes > 0
            )');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movies');
    }
};
