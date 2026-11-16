<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacySocialAccountMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_schema_adopts_legacy_github_connection_without_data_loss(): void
    {
        $user = User::factory()->create();
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->renameColumn('provider_user_id', 'provider_id');
            $table->dropColumn('provider_email');
        });
        DB::table('social_accounts')->insert([
            'user_id' => $user->id, 'provider' => 'github', 'provider_id' => '123456',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_14_195404_create_social_accounts_table.php');
        $migration->up();
        $migration->up();

        $connection = SocialAccount::sole();
        $this->assertSame('123456', $connection->provider_user_id);
        $this->assertSame($user->id, $connection->user->id);
        $this->assertNull($connection->provider_email);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        SocialAccount::create(['user_id' => User::factory()->create()->id,
            'provider' => 'github', 'provider_user_id' => '123456']);
    }
}
