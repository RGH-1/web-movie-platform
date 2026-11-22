<?php

namespace App\Http\Requests;

use App\Models\MovieNight;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class WriteMovieNightRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('POST')) {
            return $this->user()?->can('create', MovieNight::class) ?? false;
        }

        $night = $this->route('movieNight');

        return $night instanceof MovieNight
            && ($this->user()?->can('update', $night) ?? false);
    }

    public function validationData(): array
    {
        $data = $this->isJson()
            ? $this->json()->all()
            : $this->request->all();

        foreach (['title', 'timezone'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        foreach (['description', 'location', 'meeting_url'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $value = trim($data[$field]);
                $data[$field] = $value === '' ? null : $value;
            }
        }

        return $data;
    }

    public function rules(): array
    {
        $required = $this->isMethod('PATCH')
            ? ['sometimes', 'required']
            : ['required'];

        return [
            'title' => [...$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'starts_at' => [
                ...$required,
                'bail',
                'string',
                'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
                'after:now',
            ],
            'timezone' => [
                'sometimes', 'required', 'string', 'max:64', 'timezone',
            ],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meeting_url' => [
                'sometimes', 'nullable', 'string', 'max:2048', 'url:http,https',
            ],
            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    MovieNight::STATUS_DRAFT,
                    MovieNight::STATUS_SCHEDULED,
                ]),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $keys = array_keys($this->validationData());
                $allowed = [...MovieNight::EDITABLE_FIELDS, 'status'];

                foreach (array_diff($keys, $allowed) as $key) {
                    $validator->errors()->add(
                        $key,
                        'This field cannot be submitted.',
                    );
                }

                if ($this->isMethod('PATCH')
                    && array_intersect($keys, $allowed) === []) {
                    $validator->errors()->add(
                        'movie_night',
                        'Include at least one editable field.',
                    );
                }
            },
        ];
    }
}