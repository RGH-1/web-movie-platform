<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained('movies')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('review_text')->nullable();

            $table->enum('moderation_status', ['visible', 'hidden'])->default('visible');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_reason')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'movie_id']);
            $table->index(
                ['movie_id', 'moderation_status', 'created_at'],
                'reviews_movie_status_created_at_index',
            );
            $table->index('moderated_by');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE reviews ADD CONSTRAINT reviews_rating_range_check CHECK (rating BETWEEN 1 AND 5)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
