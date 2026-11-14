<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class OAuthPasswordlessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', [
            ...config('sanctum.stateful', []),
            '127.0.0.1:5173',
        ]);

        $this->withHeader('Origin', 'http://127.0.0.1:5173');
    }

    private function createSocialUser(): User
    {
        $user = User::factory()->create([
            'email' => 'social@example.com',
            'password' => null,
        ]);

        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-123',
            'provider_email' => 'social@example.com',
        ]);

        return $user;
    }

    public function test_passwordless_user_cannot_use_password_login(): void
    {
        $this->createSocialUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'social@example.com',
            'password' => 'RandomPassword123!',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid email or password.');

        $this->assertGuest('web');
    }

    public function test_passwordless_user_can_request_password_reset(): void
    {
        Notification::fake();

        $user = $this->createSocialUser();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class
        );
    }

    public function test_passwordless_user_can_establish_local_password(): void
    {
        $user = $this->createSocialUser();

        $token = Password::createToken($user);

        $newPassword = 'NewSecurePassword123!';

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Password reset. Please sign in again.'
            );

        $this->assertTrue(
            Hash::check($newPassword, $user->fresh()->password)
        );

        Auth::forgetGuards();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $newPassword,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }
}
