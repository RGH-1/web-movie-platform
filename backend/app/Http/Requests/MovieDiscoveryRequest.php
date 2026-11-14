<?php

namespace App\Http\Requests;

use App\Services\TmdbService;
use Illuminate\Validation\Rule;

class MovieDiscoveryRequest extends MovieFeedRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'genre_id' => ['sometimes', 'bail', 'required', 'integer', 'between:1,2147483647'],
            'year' => ['sometimes', 'bail', 'required', 'digits:4', 'integer', 'between:1874,'.(now()->year + 5)],
            'min_rating' => ['sometimes', 'bail', 'required', 'numeric', 'between:0,10'],
            'sort_by' => ['sometimes', 'bail', 'required', 'string', Rule::in(array_keys(TmdbService::DISCOVERY_SORTS))],
        ];
    }
}
