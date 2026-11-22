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
            'Facebook' => ['facebook'],
        ];
    }

    public static function emailRequiredProviders(): array
    {
        return [
            'Google' => ['google'],
            'GitHub' => ['github'],
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

    #[DataProvider('providers')]
    public function test_successful_social_login_returns_to_the_requested_movie_list(string $provider): void
    {
        Socialite::fake($provider, SocialiteUser::fake([
            'id' => 'list-viewer',
            'email' => 'list-viewer@example.com',
            'email_verified' => true,
        ]));

        $path = '/lists/9007199254740993';
        $this->get("/auth/{$provider}/redirect?" . http_build_query(['return_to' => $path]))
            ->assertRedirect();
        $this->get("/auth/{$provider}/callback")
            ->assertRedirect('http://127.0.0.1:5173' . $path)
            ->assertSessionMissing("oauth.list_return_to.{$provider}");
    }

    public function test_external_destination_does_not_override_social_login_landing_page(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'list-viewer',
            'email' => 'list-viewer@example.com',
            'email_verified' => true,
        ]));

        $this->get('/auth/google/redirect?' . http_build_query(['return_to' => '//example.com/lists/1']))
            ->assertRedirect();
        $this->get('/auth/google/callback')->assertRedirect('http://127.0.0.1:5173');
    }

    #[DataProvider('emailRequiredProviders')]
    public function test_existing_social_account_logs_into_same_user(
        string $provider,
    ): void {
        $user = User::factory()->create();
        $user->socialAccounts()->create([
            'provider' => $provider,
            'provider_user_id' => 'returning-identity',
            'provider_email' => $user->email,
        ]);

        Socialite::fake($provider, SocialiteUser::fake([
            'id' => 'returning-identity',
            'name' => 'Updated Provider Name',
            'email' => 'changed@example.com',
            'email_verified' => true,
        ]));

        $this->get("/auth/{$provider}/callback")
            ->assertRedirect('http://127.0.0.1:5173');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => 'returning-identity',
        ]);
    }

    #[DataProvider('emailRequiredProviders')]
    public function test_existing_email_cannot_be_linked_automatically(
        string $provider,
    ): void {
        $existingUser = User::factory()->create(['email' => 'alex@example.com']);

        Socialite::fake($provider, SocialiteUser::fake([
            'id' => 'new-provider-identity',
            'name' => 'Alex',
            'email' => 'ALEX@example.com',
            'email_verified' => true,
        ]));

        $this->get("/auth/{$provider}/callback")
            ->assertRedirect('http://127.0.0.1:5173/login?oauth_error=account_exists');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'email' => 'alex@example.com',
        ]);
    }

    #[DataProvider('emailRequiredProviders')]
    public function test_missing_non_facebook_provider_email_is_rejected(
        string $provider,
    ): void {
        Socialite::fake($provider, SocialiteUser::fake([
            'id' => 'missing-email-identity',
            'name' => 'Alex',
            'email' => null,
            'email_verified' => true,
        ]));

        $this->get("/auth/{$provider}/callback")
            ->assertRedirect('http://127.0.0.1:5173/login?oauth_error=email_required');

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



    #[DataProvider('providers')]
    public function test_social_login_works_with_sanctum_and_logout(
        string $provider,
    ): void {
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        $email = "{$provider}@example.com";

        Socialite::fake($provider, SocialiteUser::fake([
            'id' => 'test-identity',
            'name' => 'Alex Smith',
            'email' => $email,
            'email_verified' => true,
        ]));

        $callback = $this->get("/auth/{$provider}/callback")
            ->assertRedirect('http://127.0.0.1:5173');

        $user = User::where('email', $email)->firstOrFail();
        $cookieName = config('session.cookie');

        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCredentials()
            ->withUnencryptedCookie(
                $cookieName,
                $callback->getCookie($cookieName, false)->getValue(),
            );

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $email);

        $this->getJson('/api/v1/me/profile')->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('meta.profile_complete', true);

        $logout = $this->postJson('/api/v1/auth/logout')->assertNoContent();

        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->withUnencryptedCookie(
            $cookieName,
            $logout->getCookie($cookieName, false)->getValue(),
        )->getJson('/api/v1/me')->assertUnauthorized();
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


    public function test_new_facebook_account_with_email_is_created_and_authenticated(): void
    {
        Socialite::fake('facebook', SocialiteUser::fake([
            'id' => 'facebook-new-123',
            'name' => 'Alex Smith',
            'email' => 'alex.facebook@example.com',
        ]));

        $this->get('/auth/facebook/callback')
            ->assertRedirect('http://127.0.0.1:5173');

        $user = User::where('email', 'alex.facebook@example.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_user_id' => 'facebook-new-123',
            'provider_email' => 'alex.facebook@example.com',
        ]);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_new_facebook_account_without_email_is_created_and_authenticated(): void
    {
        Socialite::fake('facebook', SocialiteUser::fake([
            'id' => 'facebook-no-email-123', 'name' => 'Alex Smith', 'email' => null,
        ]));

        $this->get('/auth/facebook/callback')->assertRedirect('http://127.0.0.1:5173');

        $user = User::whereNull('email')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_user_id' => 'facebook-no-email-123',
            'provider_email' => null,
        ]);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_malformed_facebook_email_is_rejected(): void
    {
        Socialite::fake('facebook', SocialiteUser::fake([
            'id' => 'facebook-bad-email-123', 'name' => 'Alex', 'email' => 'not-an-email',
        ]));

        $this->get('/auth/facebook/callback')->assertRedirect(
            'http://127.0.0.1:5173/login?oauth_error=invalid_email'
        );
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_multiple_facebook_ids_can_register_without_email(): void
    {
        foreach (['facebook-null-1', 'facebook-null-2'] as $id) {
            Socialite::fake('facebook', SocialiteUser::fake([
                'id' => $id, 'name' => 'Alex', 'email' => null,
            ]));
            $this->get('/auth/facebook/callback')->assertRedirect('http://127.0.0.1:5173');
            Auth::guard('web')->logout();
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('social_accounts', 2);
        $this->assertSame(2, User::whereNull('email')->count());
    }

    public function test_facebook_is_not_linked_to_an_existing_email_account(): void
    {
        User::factory()->create(['email' => 'alex@example.com']);
        Socialite::fake('facebook', SocialiteUser::fake([
            'id' => 'facebook-email-match-123', 'name' => 'Alex', 'email' => 'alex@example.com',
        ]));

        $this->get('/auth/facebook/callback')->assertRedirect(
            'http://127.0.0.1:5173/login?oauth_error=account_exists'
        );
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
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

    public function test_provider_failure_leaves_the_viewer_signed_out(): void
    {
        $driver = \Mockery::mock();
        $driver->shouldReceive('user')
            ->once()
            ->andThrow(new \RuntimeException('Provider unavailable.'));

        Socialite::shouldReceive('driver')
            ->once()
            ->with('github')
            ->andReturn($driver);

        $this->get('/auth/github/callback')
            ->assertRedirect('http://127.0.0.1:5173/login?oauth_error=provider_failed');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }
}
