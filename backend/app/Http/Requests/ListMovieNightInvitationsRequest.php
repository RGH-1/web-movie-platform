<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListMovieNightInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'integer', 'between:1,2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'between:1,50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), ['page', 'per_page']) as $key) {
                $validator->errors()->add($key, 'This query parameter is not supported.');
            }
        }];
    }
}
