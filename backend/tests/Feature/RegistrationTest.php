<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['127.0.0.1:5173']);

        $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5173',
            'Referer' => 'http://127.0.0.1:5173/',
        ]);
    }

    // Default valid registration information.
    private function validData(array $changes = []): array
    {
        return array_replace([
            'name' => 'Sarah',
            'email' => 'sarah@example.com',
            'password' => 'MyPassword12345',
            'password_confirmation' => 'MyPassword12345',
        ], $changes);
    }

    // TEST 1: Successful registration.
    public function test_user_can_register_successfully(): void
    {
        $password = '  MyPassword12345  ';

        $response = $this->postJson(
            '/api/v1/auth/register',
            $this->validData([
                'name' => '  Sarah  ',
                'email' => '  SARAH@EXAMPLE.COM  ',
                'password' => $password,
                'password_confirmation' => $password,
            ])
        );

        $response->assertStatus(201);

        $user = User::where('email', 'sarah@example.com')
            ->firstOrFail();

        // Name and email must be normalized.
        $this->assertSame('Sarah', $user->name);
        $this->assertSame('sarah@example.com', $user->email);

        // Password must be hashed, without changing its value.
        $this->assertNotSame($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));

        // Response must contain only public fields.
        $response->assertExactJson([
            'data' => [
                'id' => $user->id,
                'name' => 'Sarah',
                'email' => 'sarah@example.com',
            ],
        ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($user, 'web');
    }

    // TEST 2: Minimum allowed password length.
    public function test_password_with_exactly_12_characters_is_accepted(): void
    {
        $password = 'Abcd1234efgh';

        $response = $this->postJson(
            '/api/v1/auth/register',
            $this->validData([
                'password' => $password,
                'password_confirmation' => $password,
            ])
        );

        $response->assertStatus(201);

        $user = User::firstOrFail();

        $this->assertTrue(Hash::check($password, $user->password));
    }

    // TEST 3: Maximum allowed password byte length.
    public function test_password_with_exactly_72_bytes_is_accepted(): void
    {
        $password = str_repeat('A', 72);

        $response = $this->postJson(
            '/api/v1/auth/register',
            $this->validData([
                'password' => $password,
                'password_confirmation' => $password,
            ])
        );

        $response->assertStatus(201);

        $user = User::firstOrFail();

        $this->assertTrue(Hash::check($password, $user->password));
    }

    // TEST 4: All required fields are missing.
    public function test_all_registration_fields_are_required(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
                'password_confirmation',
            ]);

        $this->assertDatabaseCount('users', 0);
    }

    // TEST 5: All invalid input scenarios.
    #[DataProvider('invalidRegistrationCases')]
    public function test_invalid_registration_is_rejected(
        array $changes,
        array $expectedErrors
    ): void {
        $response = $this->postJson(
            '/api/v1/auth/register',
            $this->validData($changes)
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors($expectedErrors);

        // Invalid data must never create an account.
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public static function invalidRegistrationCases(): array
    {
        $longPassword = str_repeat('A', 73);
        $unicodePassword = str_repeat('é', 37);

        return [
            // NAME VALIDATION
            'missing name' => [
                ['name' => null],
                ['name'],
            ],

            'empty name' => [
                ['name' => '   '],
                ['name'],
            ],

            'name exceeds 255 characters' => [
                ['name' => str_repeat('A', 256)],
                ['name'],
            ],

            'name is not a string' => [
                ['name' => ['Sarah']],
                ['name'],
            ],

            // EMAIL VALIDATION
            'missing email' => [
                ['email' => null],
                ['email'],
            ],

            'invalid email format' => [
                ['email' => 'not-an-email'],
                ['email'],
            ],

            'email exceeds 255 characters' => [
                ['email' => str_repeat('a', 250) . '@example.com'],
                ['email'],
            ],

            'email is not a string' => [
                ['email' => ['sarah@example.com']],
                ['email'],
            ],

            // PASSWORD VALIDATION
            'missing password' => [
                ['password' => null],
                ['password'],
            ],

            'password shorter than 12 characters' => [
                [
                    'password' => 'short',
                    'password_confirmation' => 'short',
                ],
                ['password'],
            ],

            'password is not a string' => [
                ['password' => ['MyPassword12345']],
                ['password'],
            ],

            'password exceeds 72 bytes' => [
                [
                    'password' => $longPassword,
                    'password_confirmation' => $longPassword,
                ],
                ['password'],
            ],

            'unicode password exceeds 72 bytes' => [
                [
                    'password' => $unicodePassword,
                    'password_confirmation' => $unicodePassword,
                ],
                ['password'],
            ],

            'password contains NUL character' => [
                [
                    'password' => "MyPassword12345\0",
                    'password_confirmation' => "MyPassword12345\0",
                ],
                ['password'],
            ],

            // PASSWORD CONFIRMATION
            'missing password confirmation' => [
                ['password_confirmation' => null],
                ['password_confirmation'],
            ],

            'password confirmation does not match' => [
                ['password_confirmation' => 'DifferentPassword123'],
                ['password_confirmation'],
            ],

            'password confirmation is not a string' => [
                ['password_confirmation' => ['MyPassword12345']],
                ['password_confirmation'],
            ],
        ];
    }

    // TEST 6: Duplicate email, regardless of capitalization.
    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'sarah@example.com',
        ]);

        $response = $this->postJson(
            '/api/v1/auth/register',
            $this->validData([
                'email' => '  SARAH@EXAMPLE.COM  ',
            ])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    // TEST 7: Already authenticated users cannot register.
    public function test_authenticated_user_cannot_register_again(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $response = $this->postJson(
            '/api/v1/auth/register',
            $this->validData()
        );

        $response->assertStatus(409);

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($user, 'web');
    }

    // TEST 8: Authenticated users should receive 409
    // even when their submitted registration data is invalid.
    public function test_authenticated_user_with_invalid_data_receives_409(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $response = $this->postJson(
            '/api/v1/auth/register',
            []
        );

        $response->assertStatus(409);

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($user, 'web');
    }

    // TEST 9: Registration requires a stateful session.
    public function test_registration_without_stateful_session_is_rejected(): void
    {
        $this->withoutHeaders(['Origin', 'Referer']);

        $response = $this->postJson(
            '/api/v1/auth/register',
            $this->validData()
        );

        $response->assertStatus(419);

        $this->assertDatabaseCount('users', 0);
    }
}
