<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MovieSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $query = $this->query('query');

        if (is_string($query)) {
            $this->merge([
                'query' => trim($query),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'query' => ['bail', 'required', 'string', 'max:200'],
            'page' => ['sometimes', 'bail', 'required', 'integer', 'between:1,500'],
        ];
    }
}
