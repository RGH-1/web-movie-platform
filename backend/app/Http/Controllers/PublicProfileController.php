<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class PublicProfileController extends Controller
{
    public function __invoke(string $userId): JsonResponse
    {
        $id = filter_var($userId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        abort_if($id === false || (string) $id !== $userId, 404, 'Profile not found.');

        $user = User::query()->find($id, ['id', 'name']);

        abort_if($user === null, 404, 'Profile not found.');

        return response()->json([
            'data' => [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
            ],
        ]);
    }
}