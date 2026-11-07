<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge([
                'email' => mb_strtolower(trim($email)),
            ]);
        }
    }

    public function rules(): array
    {
        $passwordBytes = static function (
            string $attribute,
            mixed $value,
            Closure $fail
        ): void {
            if (! is_string($value)) {
                return;
            }

            if (strlen($value) > 72 || str_contains($value, "\0")) {
                $fail(
                    'The password must be at most 72 bytes and cannot contain a NUL character.'
                );
            }
        };

        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'bail', //Stop checking this field after its first validation failure
                'required',
                'string',
                'max:255',
                'email:rfc', //Check that the email follows accepted email formatting rules
                'unique:users,email', //Check that the email isn't already present in the users table
            ],

            'password' => [
                'bail',
                'required',
                'string',
                'min:12',
                $passwordBytes,
            ],

            'password_confirmation' => [
                'bail',
                'required',
                'string',
                'same:password',
            ],
        ];
    }
}
