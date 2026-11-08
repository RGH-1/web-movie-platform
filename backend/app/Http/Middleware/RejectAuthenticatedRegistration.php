<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RejectAuthenticatedRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check whether the user is already logged in.
        if (Auth::guard('web')->check()) {
            return response()->json([
                'message' => 'Already authenticated.',
            ], 409);
        }

        // Otherwise, continue to the registration controller.
        return $next($request);
    }
}
