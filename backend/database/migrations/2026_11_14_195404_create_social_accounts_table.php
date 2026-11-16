
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adopt connections created by the earlier GitHub-only integration.
        // Preserve the provider ID, user ownership, and existing unique indexes.
        if (Schema::hasTable('social_accounts')) {
            if (Schema::hasColumn('social_accounts', 'provider_id')
                && ! Schema::hasColumn('social_accounts', 'provider_user_id')) {
                Schema::table('social_accounts', function (Blueprint $table) {
                    $table->renameColumn('provider_id', 'provider_user_id');
                });
            }
            if (! Schema::hasColumn('social_accounts', 'provider_email')) {
                Schema::table('social_accounts', function (Blueprint $table) {
                    $table->string('provider_email')->nullable();
                });
            }

            return;
        }
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('provider', 32);
            $table->string('provider_user_id');
            $table->string('provider_email')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
