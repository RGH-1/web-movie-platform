<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('user_follows', 'follows');
    }

    public function down(): void
    {
        Schema::rename('follows', 'user_follows');
    }
};
