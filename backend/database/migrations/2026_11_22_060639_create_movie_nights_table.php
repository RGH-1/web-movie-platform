<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_nights', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organizer_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Absolute start instant, stored in UTC.
            $table->dateTime('starts_at');
            $table->string('timezone', 64)->default('UTC');

            $table->string('location')->nullable();
            $table->text('meeting_url')->nullable();

            $table->enum('status', [
                'draft',
                'scheduled',
                'finalized',
                'cancelled',
            ])->default('draft');

            $table->foreignId('winning_movie_id')
                ->nullable()
                ->constrained('movies')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['organizer_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
            $table->index('winning_movie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_nights');
    }
};