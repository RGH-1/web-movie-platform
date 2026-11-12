<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class WriteReviewRequest extends FormRequest
{
    private const EDITABLE_FIELDS = ['rating', 'review_text'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }


    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $ratingRules = ['bail', 'required', 'integer:strict', 'between:1,5'];

        return [
            'rating' => $this->isMethod('PATCH') ? ['sometimes', ...$ratingRules] : $ratingRules,
            'review_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $keys = array_keys($this->validationData());

                foreach (array_diff($keys, self::EDITABLE_FIELDS) as $key) {
                    $validator->errors()->add($key, 'This field cannot be submitted.');
                }

                if ($this->isMethod('PATCH') && array_intersect($keys, self::EDITABLE_FIELDS) === []) {
                    $validator->errors()->add('review', 'Include rating or review_text.');
                }
            },
        ];
    }
}