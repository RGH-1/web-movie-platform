<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoginLogoutTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = '/api/v1/auth/login';

    private const LOGOUT = '/api/v1/auth/logout';

    private const FRONTEND_ORIGIN = 'http://127.0.0.1:5173';

    
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'sanctum.stateful' => ['127.0.0.1:5173'],
        ]);

        // Laravel normally skips CSRF verification in the testing environment.
        $this->app->instance('env', 'local');
        $this->withHeader('Origin', self::FRONTEND_ORIGIN)->withCredentials();
    }

    public function test_login_returns_safe_user_fields_and_starts_a_session(): void
    {
        $user = User::factory()->create([
            'email' => 'sam@example.com',
            'password' => Hash::make(' spaced passphrase '),
        ]);

        $this->startBrowserSession();
        $guestSessionId = session()->getId();

        $this->postJson(self::LOGIN, [
            'email' => ' SAM@EXAMPLE.COM ',
            'password' => ' spaced passphrase ',
        ])->assertOk()
            ->assertExactJson(['data' => $user->only(['id', 'name', 'email'])])
            ->assertCookie(config('session.cookie'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($guestSessionId, session()->getId());
    }

    public function test_wrong_password_and_unknown_email_return_the_same_error(): void
    {
        $user = User::factory()->create();
        $this->startBrowserSession();

        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->postJson(self::LOGIN, [
                'email' => $email,
                'password' => 'incorrect',
            ])->assertUnauthorized()
                ->assertExactJson(['message' => 'Invalid email or password.']);
        }

        $this->assertGuest('web');
    }

    public function test_missing_or_malformed_credentials_return_field_errors(): void
    {
        $this->startBrowserSession();

        $this->postJson(self::LOGIN)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $this->postJson(self::LOGIN, [
            'email' => 'invalid-email',
            'password' => str_repeat('é', 37),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_signed_in_user_cannot_switch_accounts_through_login(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->startBrowserSession();

        $login = $this->postJson(self::LOGIN, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->useBrowserSessionFrom($login);

        $this->postJson(self::LOGIN, [
            'email' => $otherUser->email,
            'password' => 'password',
        ])->assertConflict();

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_logout_invalidates_the_session_and_rejects_reuse_of_the_old_cookie(): void
    {
        $user = User::factory()->create();
        $this->startBrowserSession();

        $login = $this->postJson(self::LOGIN, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->useBrowserSessionFrom($login);
        $loginSessionId = session()->getId();
        $loginCsrfToken = session()->token();

        $logout = $this->postJson(self::LOGOUT)->assertNoContent();

        $this->assertGuest('web');
        $this->assertNotSame($loginSessionId, session()->getId());
        $this->assertNotSame($loginCsrfToken, session()->token());

        $this->useBrowserSessionFrom($logout);
        $this->postJson(self::LOGOUT)->assertUnauthorized();

        $this->useBrowserSessionFrom($login);
        $this->postJson(self::LOGOUT)->assertStatus(419);
    }

    public function test_guest_with_valid_csrf_cannot_log_out(): void
    {
        $this->startBrowserSession();

        $this->postJson(self::LOGOUT)->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_login_requires_csrf_and_a_stateful_origin(): void
    {
        $credentials = ['email' => 'sam@example.com', 'password' => 'password'];

        $this->postJson(self::LOGIN, $credentials)->assertStatus(419);

        $this->withHeader('Origin', 'https://other.example.com')
            ->postJson(self::LOGIN, $credentials)
            ->assertStatus(419)
            ->assertExactJson(['message' => 'A stateful session is required.']);
    }

    public function test_login_is_rate_limited(): void
    {
        $this->startBrowserSession();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson(self::LOGIN, [
                'email' => 'unknown@example.com',
                'password' => 'incorrect',
            ])->assertUnauthorized();
        }

        $this->postJson(self::LOGIN, [
            'email' => 'unknown@example.com',
            'password' => 'incorrect',
        ])->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message']);
    }

    private function startBrowserSession(): void
    {
        $this->useBrowserSessionFrom(
            $this->getJson('/sanctum/csrf-cookie')->assertNoContent()
        );
    }

    private function useBrowserSessionFrom(TestResponse $response): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $sessionCookie = config('session.cookie');

        $this->withUnencryptedCookie(
            $sessionCookie,
            $response->getCookie($sessionCookie, false)->getValue()
        );

        $this->withHeader(
            'X-XSRF-TOKEN',
            $response->getCookie('XSRF-TOKEN', false)->getValue()
        );
    }
}
