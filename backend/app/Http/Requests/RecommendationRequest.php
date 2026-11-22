<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecommendationRequest extends FormRequest
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
            'limit' => ['sometimes', 'integer', 'between:1,20'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $keys = array_keys($this->query());
            foreach ($keys as $key) {
                if (! in_array($key, ['limit', 'latitude', 'longitude'], true)) {
                    $validator->errors()->add($key, 'This parameter is not supported.');
                }
            }
            if ($this->has('latitude') xor $this->has('longitude')) {
                $validator->errors()->add('coordinates', 'Latitude and longitude must be supplied together.');
            }
        });
    }
}
