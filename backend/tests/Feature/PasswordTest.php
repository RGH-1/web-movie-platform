<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private function credentials(array $extra = []): array
    {
        return array_merge(['password' => 'NewSecure!Pass123', 'password_confirmation' => 'NewSecure!Pass123'], $extra);
    }

    public function test_change_requires_authentication_and_correct_current_password(): void
    {
        $this->postJson('/api/v1/auth/change-password', $this->credentials())->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/auth/change-password', $this->credentials([
            'current_password' => 'wrong',
        ]))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_change_hashes_password_revokes_sessions_and_pending_reset(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $token = Password::createToken($user);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user)->postJson('/api/v1/auth/change-password', $this->credentials([
            'current_password' => 'password',
        ]))->assertOk();
        $this->assertTrue(Hash::check('NewSecure!Pass123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertFalse(Password::tokenExists($user, $token));
        Auth::forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_forgot_responses_do_not_disclose_accounts_or_broker_throttling(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->assertExactJson($known);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])->assertOk()->assertExactJson($known);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
            $this->assertNotSame($notification->token, $stored);
            $this->assertTrue(Hash::check($notification->token, $stored));

            return true;
        });
    }

    public function test_reset_succeeds_once_and_rotates_remember_token(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'reset-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $remember = $user->remember_token;
        $data = $this->credentials(['email' => $user->email, 'token' => Password::createToken($user)]);
        $this->postJson('/api/v1/auth/reset-password', $data)->assertOk();
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'reset-session']);
        $this->postJson('/api/v1/auth/reset-password', $data)->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_expired_invalid_and_unknown_account_tokens_are_rejected_identically(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $expired = $this->postJson('/api/v1/auth/reset-password', $this->credentials([
            'email' => $user->email, 'token' => $token,
        ]))->assertUnprocessable()->json();
        foreach ([$user->email, 'missing@example.com'] as $email) {
            $this->postJson('/api/v1/auth/reset-password', $this->credentials([
                'email' => $email, 'token' => 'invalid',
            ]))->assertUnprocessable()->assertExactJson($expired);
        }
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_social_account_must_establish_password_via_reset(): void
    {
        $user = User::factory()->create(['password' => null]);
        $this->actingAs($user)->postJson('/api/v1/auth/change-password', $this->credentials([
            'current_password' => 'anything',
        ]))->assertUnprocessable();
        Auth::guard('web')->logout();
        $this->postJson('/api/v1/auth/reset-password', $this->credentials([
            'email' => $user->email, 'token' => Password::createToken($user),
        ]))->assertOk();
        $this->assertTrue(Hash::check('NewSecure!Pass123', $user->fresh()->password));
    }

    public function test_strength_confirmation_and_required_fields_are_validated(): void
    {
        $user = User::factory()->create();
        foreach (['short', 'alllowercase123!', 'ALLUPPERCASE123!', 'NoNumbersHere!', 'NoSymbolsHere123', str_repeat('Aa1!', 19)] as $index => $password) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)]);
            $this->actingAs($user)->postJson('/api/v1/auth/change-password', [
                'current_password' => 'password', 'password' => $password, 'password_confirmation' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
    }

    public function test_reset_validation_and_ip_throttling(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'token', 'password']);
        $this->postJson('/api/v1/auth/reset-password', $this->credentials([
            'email' => 'test@example.com', 'token' => 'invalid', 'password_confirmation' => 'different',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])->assertOk();
        }
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])->assertStatus(429);
    }

    public function test_reset_email_is_delivered_to_mail_transport_with_frontend_url(): void
    {
        config(['auth.password_reset_url' => 'https://frontend.example/reset-password']);
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $body = $messages->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('https://frontend.example/reset-password?token=', $body);
        $this->assertStringContainsString(urlencode($user->email), $body);
    }

    public function test_mail_transport_failure_does_not_disclose_account_existence(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->once()->andThrow(
            new \Symfony\Component\Mailer\Exception\TransportException('SMTP unavailable')
        );
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])
            ->assertOk()->assertExactJson($known);
    }
}
