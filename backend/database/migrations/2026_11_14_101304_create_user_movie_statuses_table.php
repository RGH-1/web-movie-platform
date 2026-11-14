
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_movie_statuses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('movie_id')
                ->constrained('movies')
                ->restrictOnDelete();

            $table->boolean('in_watchlist')->default(false);
            $table->timestamp('watched_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'movie_id']);

            $table->index(['user_id', 'in_watchlist']);
            $table->index(['user_id', 'watched_at']);
            $table->index('movie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_movie_statuses');
    }
};
