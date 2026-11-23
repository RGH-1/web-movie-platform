<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_night_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_night_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');
            $table->timestamps();

            $table->unique(['movie_night_id', 'invitee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_night_invitations');
    }
};
