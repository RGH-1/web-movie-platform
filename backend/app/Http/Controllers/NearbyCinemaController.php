<?php

namespace App\Http\Controllers;

use App\Services\NearbyCinemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NearbyCinemaController extends Controller
{
    public function __invoke(Request $request, NearbyCinemaService $cinemas): JsonResponse
    {
        $values = validator($request->query(), [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['required', 'integer', 'between:1,50000'],
        ])->validate();

        $results = $cinemas->nearby((float) $values['lat'], (float) $values['lng'], (int) $values['radius']);

        return response()->json(['data' => $results])
            ->header('Cache-Control', 'private, no-store');
    }
}
