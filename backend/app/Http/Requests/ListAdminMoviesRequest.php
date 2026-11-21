<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAdminMoviesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    public function validationData(): array
    {
        return $this->query();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'required', 'string', 'max:255'],
            'source' => ['sometimes', 'required', Rule::in(['local', 'tmdb'])],
            'status' => ['sometimes', 'required', Rule::in(['active', 'deactivated', 'all'])],
            'page' => ['sometimes', 'required', 'integer', 'between:1,2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'between:1,50'],
        ];
    }
}
