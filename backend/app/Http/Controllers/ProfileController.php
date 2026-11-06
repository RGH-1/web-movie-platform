<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->profileResponse($request->user());
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $attributes = $request->safe()->only(UpdateProfileRequest::EDITABLE_FIELDS);

        if (isset($attributes['email']) && $attributes['email'] !== $user->email) {
            $attributes['email_verified_at'] = null;
        }

        // Only explicitly allowed fields reach the model, never credentials.
        foreach ($attributes as $field => $value) {
            $user->setAttribute($field, $value);
        }

        try {
            $user->save();
        } catch (UniqueConstraintViolationException $exception) {
            // Another account may claim the email after validation.
            if (array_key_exists('email', $attributes)
                && User::where('email', $attributes['email'])->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['The email has already been taken.'],
                ]);
            }

            throw $exception;
        }

        return $this->profileResponse($user->refresh());
    }

    private function profileResponse(User $user): JsonResponse
    {
        $incompleteFields = $user->incompleteProfileFields();

        return response()->json([
            'data' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'bio' => $user->bio,
                'favorite_genres' => $user->favorite_genres ?? [],
            ],
            'meta' => [
                'profile_complete' => $incompleteFields === [],
                'incomplete_fields' => $incompleteFields,
            ],
        ]);
    }
}
