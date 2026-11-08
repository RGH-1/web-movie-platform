<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'sanctum.stateful' => ['127.0.0.1:5173'],
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);

        $this->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCredentials();

        Http::preventStrayRequests();

        Http::fake([
            'api.themoviedb.org/*' => Http::response([
                'page' => 1,
                'results' => [],
                'total_pages' => 0,
                'total_results' => 0,
            ]),
        ]);
    }

    public function test_guests_can_access_health_and_csrf_endpoints(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->getJson('/sanctum/csrf-cookie')
            ->assertNoContent();

        Http::assertNothingSent();
    }

    public function test_guests_can_access_the_temporary_tmdb_integration_endpoint(): void
    {
        $this->getJson(
            '/api/integration/tmdb/search?query=Interstellar'
        )->assertOk()
            ->assertExactJson(['results' => []]);

        Http::assertSentCount(1);
    }

    #[DataProvider('publicAuthenticationEndpoints')]
    public function test_guest_auth_requests_reach_validation(
        string $url,
        array $requiredFields,
    ): void {
        $this->postJson($url)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($requiredFields);

        Http::assertNothingSent();
    }

    public static function publicAuthenticationEndpoints(): array
    {
        return [
            'registration' => [
                '/api/v1/auth/register',
                ['name', 'email', 'password', 'password_confirmation'],
            ],
            'login' => [
                '/api/v1/auth/login',
                ['email', 'password'],
            ],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_protected_endpoints_reject_guests_with_json(
        string $method,
        string $url,
    ): void {
        foreach (['application/json', 'text/html'] as $accept) {
            $this->json($method, $url, [], ['Accept' => $accept])
                ->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/json')
                ->assertExactJson([
                    'message' => 'Unauthenticated.',
                ]);
        }

        Http::assertNothingSent();
    }

    public static function protectedEndpoints(): array
    {
        return [
            'current user' => ['GET', '/api/v1/me'],
            'read profile' => ['GET', '/api/v1/me/profile'],
            'update profile' => ['PATCH', '/api/v1/me/profile'],
            'logout' => ['POST', '/api/v1/auth/logout'],
            'movie search' => [
                'GET',
                '/api/v1/movies/search?query=Interstellar',
            ],
        ];
    }

    public function test_complete_account_can_access_protected_features(): void
    {
        $user = User::factory()->create([
            'name' => 'Sam Example',
            'email' => 'sam@example.com',
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->getJson('/api/v1/movies/search?query=Interstellar')
            ->assertOk()
            ->assertJsonPath('data', []);

        Http::assertSentCount(1);
    }

    public function test_incomplete_account_can_manage_profile_but_cannot_search(): void
    {
        $user = User::factory()->create(['name' => ' ']);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('meta.profile_complete', false);

        $this->patchJson('/api/v1/me/profile', [
            'bio' => 'I enjoy mystery movies.',
        ])->assertOk();

        $this->getJson('/api/v1/movies/search?query=Interstellar')
            ->assertForbidden()
            ->assertJsonPath('code', 'PROFILE_INCOMPLETE');

        Http::assertNothingSent();
    }
}
