<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const USERS = '/api/v1/admin/users';

    public function test_only_administrators_can_list_or_change_users(): void
    {
        $user = User::factory()->create();
        $url = self::USERS.'/'.$user->getKey().'/status';
        $this->getJson(self::USERS)->assertUnauthorized();
        $this->patchJson($url, ['status' => 'disabled'])->assertUnauthorized();

        $this->actingAs($user, 'web');
        $this->getJson(self::USERS)->assertForbidden();
        $this->patchJson($url, ['status' => 'disabled'])->assertForbidden();
        $this->assertNull($user->fresh()->disabled_at);
    }

    public function test_listing_searches_name_and_email_and_returns_safe_paginated_data(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $named = User::factory()->create(['name' => 'Film_Club']);
        $email = User::factory()->create(['email' => 'film_club@example.com', 'disabled_at' => now()]);
        User::factory()->create(['name' => 'FilmXClub']);
        $this->actingAs($admin, 'web');

        $this->getJson(self::USERS.'?search=film_club&per_page=1')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', (string) $named->id)
            ->assertJsonPath('data.0.status', 'enabled')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');
        $this->getJson(self::USERS.'?search=film_club&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $email->id)
            ->assertJsonPath('data.0.status', 'disabled');
        $this->getJson(self::USERS.'?per_page=51')->assertUnprocessable();
        $this->getJson('/api/v1/me/profile')->assertJsonPath('data.can_access_admin', true);
    }

    public function test_all_administrators_are_protected_and_status_is_validated(): void
    {
        [$admin, $otherAdmin] = User::factory()->count(2)->create(['is_admin' => true]);
        $user = User::factory()->create();
        $this->actingAs($admin, 'web');

        foreach ([$admin, $otherAdmin] as $protected) {
            $this->patchJson(self::USERS.'/'.$protected->id.'/status', ['status' => 'disabled'])
                ->assertForbidden();
            $this->assertNull($protected->fresh()->disabled_at);
        }
        $this->patchJson(self::USERS.'/'.$user->id.'/status', ['status' => 'bogus'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson(self::USERS.'/'.$user->id.'/status?status=disabled')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson(self::USERS.'/01/status', ['status' => 'disabled'])->assertNotFound();
    }

    public function test_disabling_revokes_database_sessions_and_reenabling_requires_fresh_login(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['remember_token' => 'old-remember-token']);
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'session.driver' => 'database',
            'sanctum.stateful' => ['localhost:5173'],
        ]);
        $this->withHeader('Origin', 'http://localhost:5173')->withCredentials();
        $credentials = ['email' => $member->email, 'password' => 'password'];
        $url = self::USERS.'/'.$member->id.'/status';
        $cookieName = config('session.cookie');

        $login = $this->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $originalCookie = $login->getCookie($cookieName, false)->getValue();
        $originalSessionId = session()->getId();
        $this->assertDatabaseHas('sessions', ['id' => $originalSessionId, 'user_id' => $member->id]);

        $this->resetWebGuard();
        $this->actingAs($admin, 'web')->patchJson($url, ['status' => 'disabled'])->assertOk()
            ->assertJsonPath('data.status', 'disabled');
        $this->assertDatabaseMissing('sessions', ['user_id' => $member->id]);
        $this->assertNotSame('old-remember-token', $member->fresh()->remember_token);

        $this->resetWebGuard();
        $this->withUnencryptedCookie($cookieName, $originalCookie)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', $credentials)->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid email or password.');

        $this->resetWebGuard();
        $this->withUnencryptedCookie($cookieName, '')->actingAs($admin, 'web')
            ->patchJson($url, ['status' => 'enabled'])->assertOk()
            ->assertJsonPath('data.status', 'enabled');

        $this->resetWebGuard();
        $this->withUnencryptedCookie($cookieName, $originalCookie)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', $credentials)->assertOk();
    }

    public function test_a_disabled_account_is_rejected_even_if_sanctum_resolves_it(): void
    {
        $disabled = User::factory()->create(['disabled_at' => now()]);

        $this->actingAs($disabled, 'web')->getJson('/api/v1/me')->assertUnauthorized();
        $this->patchJson('/api/v1/me/profile', ['name' => 'Changed'])->assertUnauthorized();
        $this->getJson('/api/v1/movie-nights/99999999')->assertUnauthorized();
        $this->assertNotSame('Changed', $disabled->fresh()->name);
    }

    public function test_a_linked_disabled_account_cannot_sign_in_through_oauth(): void
    {
        config([
            'oauth.frontend_url' => 'http://localhost:5173',
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
        ]);
        $user = User::factory()->create(['disabled_at' => now()]);
        $user->socialAccounts()->create([
            'provider' => 'google', 'provider_user_id' => 'admin-test-subject', 'provider_email' => $user->email,
        ]);
        Socialite::fake('google', SocialiteUser::fake(['id' => 'admin-test-subject']));

        $this->get('/auth/google/callback')->assertRedirect('http://localhost:5173/login?oauth_error=account_unavailable');
        $this->assertGuest('web');
        $this->assertDatabaseCount('social_accounts', 1);
    }

    private function resetWebGuard(): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }
}
