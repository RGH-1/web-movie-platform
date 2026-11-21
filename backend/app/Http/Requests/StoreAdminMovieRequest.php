<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAdminMovieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'overview', 'director'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->movieRules(true) + $this->protectedRules();
    }

    /** @return array<string, array<int, mixed>> */
    protected function movieRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255', 'filled'],
            'overview' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'release_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'date'],
            'runtime_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,2147483647'],
            'genres' => ['sometimes', 'array', 'list'],
            'genres.*' => ['array:tmdb_id,name'],
            'genres.*.tmdb_id' => ['present', 'nullable', 'integer', 'between:1,2147483647'],
            'genres.*.name' => ['required', 'string', 'max:100', 'filled'],
            'cast' => ['sometimes', 'array', 'list'],
            'cast.*' => ['array:tmdb_person_id,name,character,profile_url'],
            'cast.*.tmdb_person_id' => ['present', 'nullable', 'integer', 'between:1,2147483647'],
            'cast.*.name' => ['required', 'string', 'max:255', 'filled'],
            'cast.*.character' => ['present', 'nullable', 'string', 'max:255'],
            'cast.*.profile_url' => ['present', 'nullable', 'url', 'starts_with:https://', 'max:2048'],
            'poster_url' => ['sometimes', 'nullable', 'url', 'starts_with:https://', 'max:2048'],
            'backdrop_url' => ['sometimes', 'nullable', 'url', 'starts_with:https://', 'max:2048'],
            'director' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trailer' => ['sometimes', 'nullable', 'array:site,key'],
            'trailer.site' => ['required_with:trailer', 'string', 'in:YouTube'],
            'trailer.key' => ['required_with:trailer', 'string', 'regex:/^[A-Za-z0-9_-]{11}$/D'],
        ];
    }

    /** @return array<string, array<int, string>> */
    protected function protectedRules(): array
    {
        return collect(['id', 'local_movie_id', 'source', 'tmdb_id', 'vote_average', 'tmdb_synced_at', 'metadata_overrides', 'deactivated_at', 'user_id'])
            ->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])
            ->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = array_merge(array_keys($this->movieRules($this->isMethod('post'))), array_keys($this->protectedRules()));
            foreach (array_keys($this->all()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    $validator->errors()->add($field, 'This field is not allowed.');
                }
            }

            foreach (['genres', 'cast'] as $field) {
                foreach ((array) $this->input($field, []) as $index => $entry) {
                    if (is_array($entry) && count($entry) !== count(array_intersect_key($entry, array_flip($field === 'genres' ? ['tmdb_id', 'name'] : ['tmdb_person_id', 'name', 'character', 'profile_url'])))) {
                        $validator->errors()->add("{$field}.{$index}", 'Unexpected nested field.');
                    }
                }
            }
        });
    }
}
