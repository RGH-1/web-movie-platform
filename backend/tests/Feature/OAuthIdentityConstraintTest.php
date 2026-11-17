<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OAuthIdentityConstraintTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('duplicateIdentities')]
    public function test_fresh_schema_rejects_duplicate_identity_assignments(
        bool $sameOwner,
        string $providerUserId,
    ): void {
        $owner = User::factory()->create();
        $owner->socialAccounts()->create([
            'provider' => 'github',
            'provider_user_id' => 'identity-123',
        ]);

        $target = $sameOwner ? $owner : User::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $target->socialAccounts()->create([
            'provider' => 'github',
            'provider_user_id' => $providerUserId,
        ]);
    }

    public static function duplicateIdentities(): array
    {
        return [
            'same provider identity assigned to another user' => [false, 'identity-123'],
            'second identity from the same provider for one user' => [true, 'identity-456'],
        ];
    }
}