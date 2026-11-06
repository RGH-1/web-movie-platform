<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Fail closed even if a route accidentally omits auth:sanctum.
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $incompleteFields = $user->incompleteProfileFields();

        if ($incompleteFields !== []) {
            return response()->json([
                'message' => 'Complete your profile before accessing this feature.',
                'code' => 'PROFILE_INCOMPLETE',
                'incomplete_fields' => $incompleteFields,
            ], 403);
        }

        return $next($request);
    }
}
