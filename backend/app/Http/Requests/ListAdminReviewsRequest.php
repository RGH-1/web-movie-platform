<?php

namespace App\Http\Requests;

use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAdminReviewsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Review::class) ?? false;
    }

    public function validationData(): array
    {
        return $this->query();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => [
                'sometimes',
                'bail',
                'required',
                'integer',
                'between:1,2147483647',
            ],
            'per_page' => [
                'sometimes',
                'bail',
                'required',
                'integer',
                'between:1,50',
            ],
            'status' => [
                'sometimes',
                'required',
                Rule::in([
                    Review::STATUS_VISIBLE,
                    Review::STATUS_HIDDEN,
                ]),
            ],
        ];
    }
}
