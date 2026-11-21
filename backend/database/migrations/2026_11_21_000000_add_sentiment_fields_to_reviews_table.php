<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('sentiment_label', 16)->nullable()->after('review_text');
            $table->string('sentiment_status', 16)->default('skipped')->after('sentiment_label');
            $table->decimal('sentiment_score', 5, 4)->nullable()->after('sentiment_status');
            $table->char('sentiment_text_hash', 64)->nullable()->after('sentiment_score');
            $table->index('sentiment_status');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['sentiment_status']);
            $table->dropColumn([
                'sentiment_label',
                'sentiment_status',
                'sentiment_score',
                'sentiment_text_hash',
            ]);
        });
    }
};
