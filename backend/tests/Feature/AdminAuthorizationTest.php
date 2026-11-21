<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DevelopmentAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_default_to_non_admin_and_flag_is_protected(): void
    {
        $user = User::factory()->create()->refresh();

        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->isFillable('is_admin'));

        $user->fill([
            'name' => 'Updated name',
            'is_admin' => true,
        ])->save();

        $this->assertFalse($user->refresh()->is_admin);
        $this->assertArrayNotHasKey('is_admin', $user->toArray());

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_admin' => false,
        ]);
    }

    public function test_registration_cannot_assign_administrator_access(): void
    {
        config()->set('sanctum.stateful', ['localhost:5173']);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/register', [
                'name' => 'Attempted administrator',
                'email' => 'attempt@example.test',
                'password' => 'ExamplePassword147',
                'password_confirmation' => 'ExamplePassword147',
                'is_admin' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_admin');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_seeder_creates_admin_and_reruns_preserve_password(): void
    {
        $this->app->instance('env', 'local');

        config()->set('admin.development', [
            'name' => 'Development Admin',
            'email' => 'admin@example.test',
            'password' => 'DevelopmentPassword147',
        ]);

        $seeder = app(DevelopmentAdminSeeder::class);
        $seeder->run();

        $admin = User::query()
            ->where('email', 'admin@example.test')
            ->firstOrFail();

        $this->assertTrue($admin->is_admin);
        $this->assertTrue(
            Hash::check('DevelopmentPassword147', $admin->password)
        );

        $originalHash = $admin->password;

        config()->set(
            'admin.development.password',
            'DifferentPassword147',
        );

        $seeder->run();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($originalHash, $admin->refresh()->password);
        $this->assertTrue($admin->is_admin);
    }

    public function test_local_seeder_refuses_to_promote_an_existing_account(): void
    {
        $this->app->instance('env', 'local');

        $user = User::factory()->create([
            'email' => 'existing@example.test',
        ]);

        config()->set('admin.development', [
            'name' => 'Development Admin',
            'email' => $user->email,
            'password' => 'DevelopmentPassword147',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The configured email belongs to a non-admin account'
        );

        try {
            app(DevelopmentAdminSeeder::class)->run();
        } finally {
            $this->assertFalse($user->refresh()->is_admin);
            $this->assertDatabaseCount('users', 1);
        }
    }

    public function test_development_seeder_refuses_production(): void
    {
        $this->app->instance('env', 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'DevelopmentAdminSeeder may only run in the local environment.'
        );

        try {
            app(DevelopmentAdminSeeder::class)->run();
        } finally {
            $this->assertDatabaseCount('users', 0);
        }
    }
}