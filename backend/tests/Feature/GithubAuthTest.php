<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.github.client_id' => 'client', 'services.github.client_secret' => 'secret',
            'services.github.redirect' => 'http://localhost/auth/github/callback',
            'services.github.frontend_url' => 'http://localhost:5173']);
        Http::preventStrayRequests();
    }

    private function provider(array $emails = [], array $profile = ['id' => 123, 'login' => 'octocat', 'email' => 'untrusted@example.com']): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'private-token']),
            'api.github.com/user' => Http::response($profile),
            'api.github.com/user/emails' => Http::response($emails),
        ]);
    }

    private function completeGithub(array $query = [])
    {
        return $this->withSession(['oauth.github' => ['state' => 'test-state', 'verifier' => 'verifier', 'expires' => time() + 600]])
            ->get('/auth/github/callback?'.http_build_query($query + ['state' => 'test-state', 'code' => 'code']));
    }

    public function test_redirect_stores_state_and_pkce_and_requests_email_scope(): void
    {
        $response = $this->get('/auth/github/redirect')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $pending = session('oauth.github');
        $this->assertSame($pending['state'], $query['state']);
        $this->assertSame('read:user user:email', $query['scope']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
        Http::assertNothingSent();
    }

    public function test_signup_uses_verified_private_email_and_existing_session_authentication(): void
    {
        $this->provider([['email' => 'Private@example.com', 'primary' => true, 'verified' => true]]);
        $this->completeGithub()->assertRedirect('http://localhost:5173/');
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('private@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'github', 'provider_id' => '123', 'user_id' => $user->id]);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        Http::assertSent(fn ($request) => $request->url() === 'https://github.com/login/oauth/access_token'
            && $request['code_verifier'] === 'verifier');
    }

    public function test_missing_or_unverified_email_creates_separate_incomplete_accounts(): void
    {
        foreach ([123, 456] as $id) {
            Auth::guard('web')->logout();
            $this->provider([['email' => 'unverified@example.com', 'verified' => false, 'primary' => true]], ['id' => $id, 'login' => 'name', 'email' => 'public@example.com']);
            $this->completeGithub()->assertRedirect('http://localhost:5173/');
            $this->assertSame(['email'], Auth::guard('web')->user()->incompleteProfileFields());
        }
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(2, User::whereNull('email')->count());
    }

    public function test_returning_provider_id_does_not_create_or_relink_users_when_email_changes(): void
    {
        $this->provider();
        $this->completeGithub();
        $user = User::firstOrFail();
        Auth::guard('web')->logout();
        $this->provider([], ['id' => 123, 'login' => 'renamed', 'email' => 'changed@example.com']);
        $this->completeGithub()->assertRedirect('http://localhost:5173/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
        $this->assertNull($user->fresh()->email);
    }

    public function test_email_match_never_links_an_existing_account(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);
        $this->provider([['email' => 'existing@example.com', 'verified' => true, 'primary' => true]]);
        $this->completeGithub()->assertRedirect('http://localhost:5173/login?oauth_error=email_conflict');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_invalid_expired_and_replayed_state_are_rejected_before_provider_requests(): void
    {
        $this->completeGithub(['state' => 'wrong'])->assertRedirect('http://localhost:5173/login?oauth_error=invalid_state');
        $this->get('/auth/github/callback?state=test-state&code=code')->assertRedirect('http://localhost:5173/login?oauth_error=invalid_state');
        $this->withSession(['oauth.github' => ['state' => 'test-state', 'verifier' => 'v', 'expires' => time() - 1]])
            ->get('/auth/github/callback?state=test-state&code=code')->assertRedirect('http://localhost:5173/login?oauth_error=invalid_state');
        Http::assertNothingSent();
    }

    public function test_denial_and_missing_code_are_safe(): void
    {
        $this->completeGithub(['error' => 'access_denied'])->assertRedirect('http://localhost:5173/login?oauth_error=denied');
        $this->completeGithub(['code' => ''])->assertRedirect('http://localhost:5173/login?oauth_error=failed');
        Http::assertNothingSent();
        $this->assertGuest('web');
    }

    public function test_provider_errors_and_malformed_profile_do_not_authenticate(): void
    {
        Http::fake(['github.com/login/oauth/access_token' => Http::response(['error' => 'bad_verification_code'])]);
        $this->completeGithub()->assertRedirect('http://localhost:5173/login?oauth_error=failed');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['github.com/login/oauth/access_token' => Http::response('outage', 500)]);
        $this->completeGithub()->assertRedirect('http://localhost:5173/login?oauth_error=failed');
        $this->provider([], ['login' => 'missing-id']);
        $this->completeGithub()->assertRedirect('http://localhost:5173/login?oauth_error=failed');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_endpoint_unavailable_is_safe_for_signup(): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'token']),
            'api.github.com/user' => Http::response(['id' => 123, 'login' => 'name']),
            'api.github.com/user/emails' => Http::response([], 403),
        ]);
        $this->completeGithub()->assertRedirect('http://localhost:5173/');
        $this->assertNull(User::firstOrFail()->email);
        $this->patchJson('/api/v1/me/profile', ['email' => 'first@example.com'])
            ->assertOk()->assertJsonPath('meta.profile_complete', true);
        $this->assertNull(User::firstOrFail()->email_verified_at);
        $this->patchJson('/api/v1/me/profile', ['email' => 'second@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_database_rejects_duplicate_provider_identity(): void
    {
        $this->provider();
        $this->completeGithub();
        $other = User::factory()->create();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('social_accounts')->insert(['user_id' => $other->id, 'provider' => 'github', 'provider_id' => '123']);
    }

    public function test_authenticated_user_is_not_switched_or_linked(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->completeGithub()->assertRedirect('http://localhost:5173/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertNothingSent();
    }

    private function startLink(User $user): string
    {
        Auth::forgetGuards();
        config(['sanctum.stateful' => ['127.0.0.1:5173']]);
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://127.0.0.1:5173');
        $response = $this->postJson('/api/v1/auth/github/link', ['current_password' => 'password'])->assertOk();
        parse_str(parse_url($response->json('data.url'), PHP_URL_QUERY), $query);

        return $query['state'];
    }

    public function test_linking_requires_an_authenticated_session_and_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/github/link', ['current_password' => 'password'])->assertUnauthorized();
        $user = User::factory()->create();
        config(['sanctum.stateful' => ['127.0.0.1:5173']]);
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://127.0.0.1:5173');
        $this->postJson('/api/v1/auth/github/link', ['current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertNull(session('oauth.github'));
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_confirmed_link_uses_provider_id_and_allows_future_github_login(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);
        $state = $this->startLink($user);
        // Linking needs proof of provider ownership, not an email match.
        $this->provider([], ['id' => 123, 'login' => 'octocat', 'email' => null]);
        $this->get('/auth/github/callback?state='.$state.'&code=code')->assertRedirect('http://localhost:5173/profile?github_linked=1');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('existing@example.com', $user->fresh()->email);
        $this->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('data.github_linked', true);
        $this->assertDatabaseCount('users', 1);
        Auth::guard('web')->logout();
        $this->completeGithub()->assertRedirect('http://localhost:5173/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_link_cannot_steal_another_users_provider_or_replace_a_connection(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        DB::table('social_accounts')->insert(['user_id' => $other->id, 'provider' => 'github', 'provider_id' => '123']);
        $state = $this->startLink($user);
        $this->provider();
        $this->get('/auth/github/callback?state='.$state.'&code=code')
            ->assertRedirect('http://localhost:5173/profile?github_error=already_linked');
        $this->assertDatabaseHas('social_accounts', ['user_id' => $other->id, 'provider_id' => '123']);

        $state = $this->startLink($other);
        $this->provider([], ['id' => 456, 'login' => 'different']);
        $this->get('/auth/github/callback?state='.$state.'&code=code')
            ->assertRedirect('http://localhost:5173/profile?github_error=already_linked');
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_link_callback_rejects_changed_account_and_replayed_state(): void
    {
        $user = User::factory()->create();
        $state = $this->startLink($user);
        $this->actingAs(User::factory()->create(), 'web');
        $url = '/auth/github/callback?state='.$state.'&code=code';
        $this->get($url)->assertRedirect('http://localhost:5173/login?oauth_error=invalid_state');
        $this->actingAs($user, 'web');
        $this->get($url)->assertRedirect('http://localhost:5173/login?oauth_error=invalid_state');
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_denied_link_does_not_change_the_account(): void
    {
        $user = User::factory()->create();
        $state = $this->startLink($user);
        $this->get('/auth/github/callback?state='.$state.'&error=access_denied')
            ->assertRedirect('http://localhost:5173/profile?github_error=denied');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertNothingSent();
    }
}
