<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public const EDITABLE_FIELDS = ['name', 'email', 'bio', 'favorite_genres'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'email', 'bio'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => match ($field) {
                    'email' => mb_strtolower($value),
                    'bio' => $value === '' ? null : $value,
                    default => $value,
                }]);
            }
        }
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'bail', 'required', 'string', 'max:255',
                'email:rfc', Rule::unique('users', 'email')->ignore($this->user()),
            ],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'favorite_genres' => ['sometimes', 'array', 'list', 'max:5'],
            'favorite_genres.*' => [
                'bail', 'required', 'string', 'distinct:strict',
                Rule::in([
                    'action', 'adventure', 'animation', 'comedy', 'crime',
                    'documentary', 'drama', 'fantasy', 'horror', 'mystery',
                    'romance', 'science-fiction', 'thriller',
                ]),
            ],
        ];

        if (is_string($this->input('email')) && $this->input('email') !== $this->user()->email) {
            $rules['current_password'] = ['bail', 'required', 'string', 'current_password:web'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Inspect body keys including null values. Never use client IDs.
                $keys = array_keys($this->isJson() ? $this->json()->all() : $this->request->all());
                foreach (array_diff($keys, [...self::EDITABLE_FIELDS, 'current_password']) as $key) {
                    $validator->errors()->add($key, 'This field cannot be updated.');
                }

                if (array_intersect($keys, self::EDITABLE_FIELDS) === []) {
                    $validator->errors()->add('profile', 'Include at least one editable profile field.');
                }
            },
        ];
    }
}
