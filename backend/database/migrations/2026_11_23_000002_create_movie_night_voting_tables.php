<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_night_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_night_id')->constrained('movie_nights')->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained('movies')->restrictOnDelete();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['movie_night_id', 'movie_id']);
            $table->unique(['movie_night_id', 'id'], 'movie_night_candidate_identity');
        });

        Schema::create('movie_night_votes', function (Blueprint $table) {
            $table->foreignId('movie_night_id');
            $table->foreignId('candidate_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['movie_night_id', 'user_id']);
            $table->index(['movie_night_id', 'candidate_id']);
            $table->foreign(['movie_night_id', 'candidate_id'])
                ->references(['movie_night_id', 'id'])
                ->on('movie_night_candidates')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_night_votes');
        Schema::dropIfExists('movie_night_candidates');
    }
};
