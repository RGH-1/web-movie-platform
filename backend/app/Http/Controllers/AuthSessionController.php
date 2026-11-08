<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthSessionController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json(['message' => 'A stateful session is required.'], 419);
        }

        $guard = Auth::guard('web');

        if (! $guard->attempt($request->validated())) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        $request->session()->regenerate();

        $user = $guard->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
