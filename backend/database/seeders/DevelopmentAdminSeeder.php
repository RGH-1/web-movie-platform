<?php

namespace Database\Seeders;

use App\Models\User;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class DevelopmentAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'DevelopmentAdminSeeder may only run in the local environment.'
            );
        }

        $identity = Validator::make([
            'name' => config('admin.development.name'),
            'email' => mb_strtolower(trim(
                (string) config('admin.development.email')
            )),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ])->validate();

        $existing = User::query()
            ->where('email', $identity['email'])
            ->first();

        if ($existing !== null) {
            if (! $existing->is_admin) {
                throw new RuntimeException(
                    'The configured email belongs to a non-admin account; no role was changed.'
                );
            }

            // Rerunning the seeder must not reset an existing administrator.
            return;
        }

        $credentials = Validator::make([
            'password' => config('admin.development.password'),
        ], [
            'password' => [
                'bail',
                'required',
                'string',
                'min:12',
                static function (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ): void {
                    if (is_string($value)
                        && (strlen($value) > 72 || str_contains($value, "\0"))) {
                        $fail(
                            'The password must be at most 72 bytes and cannot contain a NUL character.'
                        );
                    }
                },
            ],
        ])->validate();

        $user = new User();

        $user->forceFill([
            'name' => $identity['name'],
            'email' => $identity['email'],
            'password' => $credentials['password'],
            'is_admin' => true,
        ])->save();
    }
}