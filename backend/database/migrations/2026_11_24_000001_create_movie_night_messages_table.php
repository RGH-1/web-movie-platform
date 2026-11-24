<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_night_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_night_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('text');
            $table->double('video_timestamp_seconds')->nullable();
            $table->boolean('is_spoiler')->default(false);
            $table->timestamps();

            $table->index(['movie_night_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_night_messages');
    }
};
