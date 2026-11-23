<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('share_activity')->default(false)->change();

            $table->boolean('share_ratings')->default(false);
            $table->boolean('share_reviews')->default(false);
            $table->boolean('share_public_lists')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'share_ratings',
                'share_reviews',
                'share_public_lists',
            ]);

            $table->boolean('share_activity')->default(true)->change();
        });
    }
};