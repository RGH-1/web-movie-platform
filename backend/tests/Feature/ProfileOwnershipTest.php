<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProfileOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = '/api/v1/me/profile';

    public function test_query_parameters_cannot_redirect_an_update_to_another_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(['bio' => 'Private bio', 'favorite_genres' => ['drama']]);
        $originalOther = $other->fresh()->getRawOriginal();

        $this->actingAs($owner, 'web')->patchJson(
            self::PROFILE.'?id='.$other->id.'&user_id='.$other->id,
            ['name' => 'Owner Updated', 'bio' => 'Owner bio', 'favorite_genres' => ['comedy']]
        )->assertOk()
            ->assertJsonPath('data.id', $owner->id)
            ->assertJsonPath('data.name', 'Owner Updated')
            ->assertJsonPath('data.bio', 'Owner bio');

        $this->assertSame('Owner Updated', $owner->fresh()->name);
        $this->assertSame(['comedy'], $owner->fresh()->favorite_genres);
        $this->assertSame($originalOther, $other->fresh()->getRawOriginal());
    }

    public function test_body_identifiers_cannot_select_another_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $originalOwner = $owner->fresh()->getRawOriginal();
        $originalOther = $other->fresh()->getRawOriginal();
        $this->actingAs($owner, 'web');

        foreach (['id', 'user_id', 'profile_id'] as $field) {
            $this->patchJson(self::PROFILE, [
                $field => $other->id, 'name' => 'Not Saved',
            ])->assertUnprocessable()->assertJsonValidationErrors($field);

            $this->assertSame($originalOwner, $owner->fresh()->getRawOriginal());
            $this->assertSame($originalOther, $other->fresh()->getRawOriginal());
        }
    }

    public function test_protected_and_unknown_fields_reject_the_entire_update_even_when_null(): void
    {
        $owner = User::factory()->create(['bio' => 'Original bio', 'favorite_genres' => ['drama']]);
        $original = $owner->fresh()->getRawOriginal();
        $this->actingAs($owner, 'web');

        $protected = [
            'id' => 999,
            'role' => 'admin',
            'permissions' => ['manage-users'],
            'email_verified_at' => '2026-01-01',
            'remember_token' => 'replacement',
            'created_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
            'password' => 'replacement password',
            'unexpected_field' => 'value',
        ];

        foreach ($protected as $field => $value) {
            foreach ([$value, null] as $submittedValue) {
                $this->patchJson(self::PROFILE, [
                    'name' => 'Not Saved',
                    'bio' => 'Not Saved',
                    'favorite_genres' => ['comedy'],
                    $field => $submittedValue,
                ])->assertUnprocessable()->assertJsonValidationErrors($field);

                $this->assertSame($original, $owner->fresh()->getRawOriginal());
            }
        }
    }

    public function test_invalid_field_does_not_partially_save_other_valid_changes(): void
    {
        $owner = User::factory()->create();
        $original = $owner->fresh()->getRawOriginal();
        $this->actingAs($owner, 'web');

        foreach ([
            ['bio' => ['not text']],
            ['email' => null],
            ['name' => null],
            ['favorite_genres' => 'drama'],
        ] as $invalid) {
            $this->patchJson(self::PROFILE, array_merge([
                'name' => 'Not Saved',
                'bio' => 'Not Saved',
                'favorite_genres' => ['comedy'],
            ], $invalid))->assertUnprocessable()
                ->assertJsonValidationErrors(array_keys($invalid));

            $this->assertSame($original, $owner->fresh()->getRawOriginal());
        }
    }

    public function test_password_confirmation_must_belong_to_the_account_owner(): void
    {
        $owner = User::factory()->create(['password' => 'owner passphrase']);
        $other = User::factory()->create(['password' => 'other passphrase']);
        $original = $owner->fresh()->getRawOriginal();
        $originalOther = $other->fresh()->getRawOriginal();

        $this->actingAs($owner, 'web')->patchJson(self::PROFILE, [
            'email' => 'new@example.com',
            'current_password' => 'other passphrase',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertSame($original, $owner->fresh()->getRawOriginal());
        $this->assertSame($originalOther, $other->fresh()->getRawOriginal());
    }

    public function test_browser_session_can_read_and_update_profile_with_csrf(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'sanctum.stateful' => ['127.0.0.1:5173'],
        ]);
        // Exercise actual CSRF verification, which tests normally bypass.
        $this->app->instance('env', 'local');
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->withCredentials();
        $owner = User::factory()->create();

        $csrf = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $this->useSessionCookies($csrf);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email, 'password' => 'password',
        ])->assertOk();
        $this->useSessionCookies($login);

        $this->getJson(self::PROFILE)->assertOk()->assertJsonPath('data.id', $owner->id);
        $this->patchJson(self::PROFILE, ['bio' => 'Updated through session'])
            ->assertOk()->assertJsonPath('data.bio', 'Updated through session');
        $this->assertSame('Updated through session', $owner->fresh()->bio);

        $this->withHeader('X-XSRF-TOKEN', 'invalid-token')
            ->patchJson(self::PROFILE, ['bio' => 'Must not save'])->assertStatus(419);
        $this->assertSame('Updated through session', $owner->fresh()->bio);
    }

    private function useSessionCookies(TestResponse $response): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->withUnencryptedCookie(
            config('session.cookie'),
            $response->getCookie(config('session.cookie'), false)->getValue()
        );
        $this->withHeader('X-XSRF-TOKEN', $response->getCookie('XSRF-TOKEN', false)->getValue());
    }
}
