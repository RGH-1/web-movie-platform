<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use App\Models\User;
use Tests\TestCase;

class AuthenticatedUserTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/me';

    public function test_authenticated_user_receives_only_the_agreed_fields(): void
    {
        $user = User::factory()->create([
            'bio' => 'I enjoy science fiction.',
            'favorite_genres' => ['science-fiction'],
        ]);

        $this->actingAs($user, 'web')
            ->getJson(self::URL)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
    }

    public function test_guest_receives_a_json_unauthenticated_response(): void
    {
        $this->getJson(self::URL)
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);

        // API errors should also be JSON when a client requests HTML.
        $this->get(self::URL, ['Accept' => 'text/html'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_query_parameters_cannot_select_another_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson(
                self::URL.'?id='.$otherUser->id.'&user_id='.$otherUser->id
            )
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
    }

    public function test_incomplete_profile_does_not_block_authentication_restoration(): void
    {
        $user = User::factory()->unverified()->create([
            'name' => ' ',
        ]);

        $this->assertSame(['name'], $user->incompleteProfileFields());

        $this->actingAs($user, 'web')
            ->getJson(self::URL)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
    }

    public function test_session_cookie_restores_the_user_and_logout_removes_access(): void
    {
        $user = User::factory()->create();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'sanctum.stateful' => ['127.0.0.1:5173'],
        ]);

        // Enable real CSRF checks for this browser-style flow.
        $this->app->instance('env', 'local');

        $this->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCredentials();

        $csrf = $this->getJson('/sanctum/csrf-cookie')
            ->assertNoContent();

        $this->useBrowserSessionFrom($csrf);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->useBrowserSessionFrom($login);

        $currentUser = $this->getJson(self::URL)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);

        $this->useBrowserSessionFrom($currentUser);

        $logout = $this->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->useBrowserSessionFrom($logout);

        $this->getJson(self::URL)
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
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
