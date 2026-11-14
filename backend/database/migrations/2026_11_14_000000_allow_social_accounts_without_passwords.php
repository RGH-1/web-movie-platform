<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('password')->nullable()->change());
    }

    public function down(): void
    {
        // Keep nullable passwords: rolling back must not lock out social accounts.
    }
};
