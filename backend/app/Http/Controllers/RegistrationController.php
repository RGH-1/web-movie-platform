<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        // 1. Make sure the request has a session.
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'A stateful session is required.',
            ], 419);
        }

        // 2. Get Laravel's session-based authentication guard.
        $guard = Auth::guard('web');

        // 3. Prevent an already logged-in user from registering.
        if ($guard->check()) {
            return response()->json([
                'message' => 'Already authenticated.',
            ], 409);
        }

        // 4. Retrieve only the validated fields we want to save.
        $data = $request->safe()->only([
            'name',
            'email',
            'password',
        ]);

        // 5. Create the new user in the database.
        try {
            $user = User::create($data);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        // 6. Log in the newly registered user.
        $guard->login($user);

        // 7. Generate a new session ID.
        $request->session()->regenerate();

        // 8. Return the new user's public information.
        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }
}
