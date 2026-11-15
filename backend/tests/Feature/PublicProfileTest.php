<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_read_only_the_requested_public_identity(): void
    {
        User::factory()->create(['name' => 'Other user']);
        $author = User::factory()->create(['name' => 'Review author']);

        $this->getJson("/api/v1/users/{$author->id}/profile")
            ->assertOk()
            ->assertExactJson([
                'data' => ['id' => (string) $author->id, 'name' => 'Review author'],
            ]);

        $this->getJson('/api/v1/me/profile')->assertUnauthorized();
    }

    public function test_invalid_and_missing_public_profiles_return_404(): void
    {
        foreach (['0', '01', 'abc', '9223372036854775808', '999999'] as $id) {
            $this->getJson("/api/v1/users/{$id}/profile")->assertNotFound();
        }
    }
}