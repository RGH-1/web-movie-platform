<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/me/profile';

    public function test_guests_cannot_read_or_update_profiles(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->patchJson(self::URL, ['name' => 'Changed'])->assertUnauthorized();
    }

    public function test_profile_returns_only_the_current_users_public_account_fields(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user, 'web')
            ->getJson(self::URL.'?id='.$other->id)
            ->assertOk()->assertExactJson(['data' => [
                'id' => $user->id, 'name' => $user->name,
                'email' => $user->email, 'bio' => null, 'favorite_genres' => [],
            ], 'meta' => ['profile_complete' => true, 'incomplete_fields' => []]]);
    }

    public function test_updates_normalize_fields_and_preserve_omitted_values(): void
    {
        $user = User::factory()->create(['bio' => 'Original', 'favorite_genres' => ['drama']]);
        $this->actingAs($user, 'web')->patchJson(self::URL, ['name' => '  New Name  '])
            ->assertOk()->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.bio', 'Original')
            ->assertJsonPath('data.favorite_genres', ['drama']);
        $this->patchJson(self::URL, ['bio' => '   ', 'favorite_genres' => []])
            ->assertOk()->assertJsonPath('data.bio', null)
            ->assertJsonPath('data.favorite_genres', []);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name', 'bio' => null]);
    }

    public function test_genre_selection_is_saved_and_retrieved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->patchJson(self::URL, [
            'favorite_genres' => ['science-fiction', 'mystery'],
        ])->assertOk()->assertJsonPath('data.favorite_genres', ['science-fiction', 'mystery']);
        $this->assertSame(['science-fiction', 'mystery'], $user->fresh()->favorite_genres);
        $this->getJson(self::URL)->assertJsonPath('data.favorite_genres', ['science-fiction', 'mystery']);
    }

    public function test_invalid_updates_save_nothing(): void
    {
        $user = User::factory()->create(['name' => 'Original']);
        $cases = [
            [['name' => ' '], 'name'],
            [['name' => str_repeat('a', 256)], 'name'],
            [['bio' => str_repeat('a', 501)], 'bio'],
            [['email' => 'invalid'], 'email'],
            [['favorite_genres' => null], 'favorite_genres'],
            [['favorite_genres' => ['unknown']], 'favorite_genres.0'],
            [['favorite_genres' => ['drama', 'drama']], 'favorite_genres.0'],
            [['favorite_genres' => ['action', 'drama', 'comedy', 'crime', 'horror', 'mystery']], 'favorite_genres'],
            [['favorite_genres' => ['one' => 'drama']], 'favorite_genres'],
            [['role' => null, 'name' => 'Changed'], 'role'],
            [['id' => 999, 'name' => 'Changed'], 'id'],
            [[], 'profile'],
        ];
        $this->actingAs($user, 'web');
        foreach ($cases as [$body, $field]) {
            $this->patchJson(self::URL, $body)->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame('Original', $user->fresh()->name);
        }
    }

    public function test_email_change_requires_exact_password_and_clears_verification(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com', 'password' => Hash::make(' spaced password '),
        ]);
        $this->actingAs($user, 'web');
        foreach ([[], ['current_password' => 'wrong'], ['current_password' => 'spaced password']] as $password) {
            $this->patchJson(self::URL, ['email' => 'new@example.com', 'name' => 'Changed', ...$password])
                ->assertUnprocessable()->assertJsonValidationErrors('current_password');
            $this->assertSame('old@example.com', $user->fresh()->email);
        }
        $this->patchJson(self::URL, [
            'email' => ' NEW@EXAMPLE.COM ', 'current_password' => ' spaced password ',
        ])->assertOk()->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonMissingPath('data.current_password');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_same_email_needs_no_password_and_duplicate_email_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);
        $this->actingAs($user, 'web')->patchJson(self::URL, ['email' => ' SAM@EXAMPLE.COM '])
            ->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->patchJson(self::URL, [
            'email' => 'taken@example.com', 'current_password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_stateful_patch_requires_csrf(): void
    {
        config(['sanctum.stateful' => ['127.0.0.1:5173']]);
        $user = User::factory()->create();
        $this->app->instance('env', 'local');
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://127.0.0.1:5173')
            ->patchJson(self::URL, ['name' => 'Changed'])->assertStatus(419);
        $this->assertNotSame('Changed', $user->fresh()->name);
    }
}
