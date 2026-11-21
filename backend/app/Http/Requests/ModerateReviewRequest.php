<?php

namespace App\Http\Requests;

use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ModerateReviewRequest extends FormRequest
{
    private const EDITABLE_FIELDS = ['status', 'reason'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $review = $this->route('review');

        return $review instanceof Review
            && ($this->user()?->can('moderate', $review) ?? false);
    }

    public function validationData(): array
    {
        $data = $this->isJson()
            ? $this->json()->all()
            : $this->request->all();

        if (isset($data['reason']) && is_string($data['reason'])) {
            $data['reason'] = trim($data['reason']);
        }

        return $data;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    Review::STATUS_VISIBLE,
                    Review::STATUS_HIDDEN,
                ]),
            ],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (
                    array_diff(
                        array_keys($this->validationData()),
                        self::EDITABLE_FIELDS,
                    ) as $key
                ) {
                    $validator->errors()->add(
                        $key,
                        'This field cannot be submitted.',
                    );
                }
            },
        ];
    }
}
