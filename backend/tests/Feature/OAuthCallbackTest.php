<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'oauth.frontend_url',
            'http://127.0.0.1:5173'
        );

        foreach (['google', 'github', 'facebook'] as $provider) {
            config()->set(
                "services.{$provider}.client_id",
                'test-client'
            );

            config()->set(
                "services.{$provider}.client_secret",
                'test-secret'
            );
        }
        config()->set('sanctum.stateful', [
            ...config('sanctum.stateful', []),
            '127.0.0.1:5173',
        ]);
    }

    public static function providers(): array
    {
        return [
            'Google' => ['google'],
            'GitHub' => ['github'],
            //'Facebook' => ['facebook'],
        ];
    }

    #[DataProvider('providers')]
    public function test_new_social_user_is_created_and_authenticated(
        string $provider
    ): void {
        Socialite::fake($provider, SocialiteUser::fake([
            'id' => "{$provider}-123",
            'name' => 'Alex Smith',
            'email' => "{$provider}@example.com",
            'email_verified' => true,
        ]));

        $this->get("/auth/{$provider}/callback")
            ->assertRedirect('http://127.0.0.1:5173');

        $user = User::where(
            'email',
            "{$provider}@example.com"
        )->firstOrFail();

        $this->assertNull($user->password);

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => "{$provider}-123",
        ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_existing_social_account_logs_into_same_user(): void
    {
        $user = User::factory()->create();

        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-999',
            'provider_email' => $user->email,
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-999',
            'name' => 'Updated Provider Name',
            'email' => 'changed@example.com',
        ]));

        $this->get('/auth/google/callback')
            ->assertRedirect('http://127.0.0.1:5173');

        $this->assertAuthenticatedAs($user, 'web');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);

        $this->assertEquals(
            $user->email,
            $user->fresh()->email
        );
    }

    public function test_existing_email_cannot_be_linked_automatically(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'alex@example.com',
        ]);

        Socialite::fake('github', SocialiteUser::fake([
            'id' => 'github-456',
            'name' => 'Alex',
            'email' => 'alex@example.com',
        ]));

        $this->get('/auth/github/callback')
            ->assertRedirect(
                'http://127.0.0.1:5173/login?oauth_error=account_exists'
            );

        $this->assertGuest('web');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 0);

        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'email' => 'alex@example.com',
        ]);
    }

    public function test_missing_provider_email_is_rejected(): void
    {
        Socialite::fake('facebook', SocialiteUser::fake([
            'id' => 'facebook-789',
            'name' => 'Alex',
            'email' => null,
        ]));

        $this->get('/auth/facebook/callback')
            ->assertRedirect(
                'http://127.0.0.1:5173/login?oauth_error=email_required'
            );

        $this->assertGuest('web');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_empty_provider_id_is_rejected(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => '',
            'name' => 'Alex',
            'email' => 'alex@example.com',
        ]));

        $this->get('/auth/google/callback')
            ->assertRedirect(
                'http://127.0.0.1:5173/login?oauth_error=invalid_identity'
            );

        $this->assertGuest('web');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_cancelled_oauth_login_creates_no_account(): void
    {
        $this->get(
            '/auth/google/callback?error=access_denied'
        )->assertRedirect(
            'http://127.0.0.1:5173/login?oauth_error=cancelled'
        );

        $this->assertGuest('web');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_invalid_oauth_state_is_handled(): void
    {
        $driver = \Mockery::mock();

        $driver->shouldReceive('user')
            ->once()
            ->andThrow(new InvalidStateException());

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($driver);

        $this->get('/auth/google/callback')
            ->assertRedirect(
                'http://127.0.0.1:5173/login?oauth_error=invalid_state'
            );

        $this->assertGuest('web');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }



    public function test_social_login_works_with_sanctum_and_logout(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-123',
            'name' => 'Alex Smith',
            'email' => 'alex@example.com',
            'email_verified' => true,
        ]));

        $this->get('/auth/google/callback')
            ->assertRedirect('http://127.0.0.1:5173');

        $user = User::where('email', 'alex@example.com')
            ->firstOrFail();

        $this->withHeader('Origin', 'http://127.0.0.1:5173');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'alex@example.com');


        $this->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertGuest('web');

        Auth::forgetGuards();

        $this->getJson('/api/v1/me')
            ->assertUnauthorized();
    }


    public function test_authenticated_user_cannot_start_another_oauth_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $this->get('/auth/google/redirect')
            ->assertStatus(409);

        $this->get('/auth/google/callback')
            ->assertStatus(409);

        $this->assertAuthenticatedAs($user, 'web');

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_real_socialite_driver_rejects_mismatched_oauth_state(): void
    {
        $this->withSession([
            'state' => 'expected-state',
        ])->get(
            '/auth/google/callback?code=fake-code&state=wrong-state'
        )->assertRedirect(
            'http://127.0.0.1:5173/login?oauth_error=invalid_state'
        );

        $this->assertGuest('web');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }


    public function test_unverified_google_email_is_rejected(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-unverified-123',
            'name' => 'Alex Smith',
            'email' => 'alex@example.com',
            'email_verified' => false,
        ]));

        $this->get('/auth/google/callback')
            ->assertRedirect(
                'http://127.0.0.1:5173/login?oauth_error=email_unverified'
            );

        $this->assertGuest('web');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }


    public function test_new_facebook_account_requires_email_verification(): void
    {
        Socialite::fake('facebook', SocialiteUser::fake([
            'id' => 'facebook-new-123',
            'name' => 'Alex Smith',
            'email' => 'alex@example.com',
            'verified' => true,
        ]));

        $this->get('/auth/facebook/callback')
            ->assertRedirect(
                'http://127.0.0.1:5173/login?oauth_error=email_verification_required'
            );

        $this->assertGuest('web');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_existing_linked_facebook_account_can_log_in(): void
    {
        $user = User::factory()->create();

        $user->socialAccounts()->create([
            'provider' => 'facebook',
            'provider_user_id' => 'facebook-existing-123',
            'provider_email' => $user->email,
        ]);

        Socialite::fake('facebook', SocialiteUser::fake([
            'id' => 'facebook-existing-123',
            'name' => 'Alex Smith',
            'email' => null,
        ]));

        $this->get('/auth/facebook/callback')
            ->assertRedirect('http://127.0.0.1:5173');

        $this->assertAuthenticatedAs($user, 'web');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
    }
}
