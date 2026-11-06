<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    private const FEATURE = '/api/v1/testing/completion-required';

    protected function setUp(): void
    {
        parent::setUp();

        // Test-only feature: production teams opt in on their own routes.
        Route::middleware(['api', 'auth:sanctum', 'profile.complete'])
            ->get(self::FEATURE, fn () => response()->json(['allowed' => true]));
        Route::middleware(['api', 'profile.complete'])
            ->get('/api/v1/testing/completion-only', fn () => response()->json(['allowed' => true]));
    }

    public function test_complete_account_needs_neither_optional_fields_nor_verified_email(): void
    {
        $user = User::factory()->unverified()->create(['bio' => null, 'favorite_genres' => null]);

        $this->assertSame([], $user->incompleteProfileFields());
        $this->actingAs($user, 'web')->getJson(self::FEATURE)->assertOk();
        $this->getJson('/api/v1/me/profile')
            ->assertJsonPath('meta.profile_complete', true)
            ->assertJsonPath('meta.incomplete_fields', []);
    }

    public function test_missing_or_invalid_required_values_are_reported(): void
    {
        $user = User::factory()->make();
        $cases = [
            ['name', null],
            ['name', '   '],
            ['name', str_repeat('a', 256)],
            ['email', null],
            ['email', '   '],
            ['email', 'invalid-email'],
            ['email', str_repeat('a', 250).'@example.com'],
        ];

        foreach ($cases as [$field, $value]) {
            $candidate = clone $user;
            $candidate->setAttribute($field, $value);
            $this->assertSame([$field], $candidate->incompleteProfileFields());
        }
    }

    public function test_incomplete_user_gets_stable_json_error_on_required_features(): void
    {
        $user = User::factory()->create(['name' => ' ', 'email' => 'invalid-email']);

        $this->actingAs($user, 'web')->getJson(self::FEATURE)
            ->assertForbidden()->assertExactJson([
                'message' => 'Complete your profile before accessing this feature.',
                'code' => 'PROFILE_INCOMPLETE',
                'incomplete_fields' => ['name', 'email'],
            ]);
    }

    public function test_guest_gets_401_even_when_only_completion_middleware_is_used(): void
    {
        $this->getJson(self::FEATURE)->assertUnauthorized();
        $this->getJson('/api/v1/testing/completion-only')->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_incomplete_user_can_read_and_repair_profile_then_access_feature(): void
    {
        $user = User::factory()->create(['name' => ' ']);

        $this->actingAs($user, 'web')->getJson('/api/v1/me/profile')->assertOk()
            ->assertJsonPath('meta.profile_complete', false)
            ->assertJsonPath('meta.incomplete_fields', ['name']);
        $this->getJson(self::FEATURE)->assertForbidden();

        $this->patchJson('/api/v1/me/profile', ['name' => '  Sam Example  '])
            ->assertOk()->assertJsonPath('meta.profile_complete', true)
            ->assertJsonPath('meta.incomplete_fields', []);

        $this->actingAs($user->fresh(), 'web')->getJson(self::FEATURE)->assertOk();
    }

    public function test_client_cannot_override_completion_status(): void
    {
        $user = User::factory()->create(['name' => ' ']);
        $this->actingAs($user, 'web')->patchJson('/api/v1/me/profile', [
            'name' => 'Sam', 'profile_complete' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('profile_complete');
        $this->assertSame(' ', $user->fresh()->name);
    }

    public function test_public_health_and_diagnostic_routes_remain_accessible(): void
    {
        config([
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.token' => 'fake-test-token',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.themoviedb.org/*' => Http::response(['results' => []])]);

        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/integration/tmdb/search?query=Interstellar')->assertOk();

        $user = User::factory()->create(['name' => ' ']);
        $this->actingAs($user, 'web')->getJson('/api/health')->assertOk();
        $this->getJson('/api/integration/tmdb/search?query=Interstellar')->assertOk();
    }
}
