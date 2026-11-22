<?php

namespace App\Http\Requests;

use App\Models\MovieNight;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelMovieNightRequest extends FormRequest
{
    public function authorize(): bool
    {
        $night = $this->route('movieNight');

        return $night instanceof MovieNight
            && ($this->user()?->can('cancel', $night) ?? false);
    }

    public function validationData(): array
    {
        return $this->isJson()
            ? $this->json()->all()
            : $this->request->all();
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys($this->validationData()) as $key) {
                    $validator->errors()->add(
                        $key,
                        'The cancellation action does not accept body fields.',
                    );
                }
            },
        ];
    }
}