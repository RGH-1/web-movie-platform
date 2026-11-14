<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->index('user_id');
            $table->index(['is_public', 'created_at']);
        });

        Schema::create('movie_list_items', function (Blueprint $table) {
            $table->foreignId('movie_list_id')->constrained('movie_lists')->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained('movies')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['movie_list_id', 'movie_id']);
            $table->index('movie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_list_items');
        Schema::dropIfExists('movie_lists');
    }
};
