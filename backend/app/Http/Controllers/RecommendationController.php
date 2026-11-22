<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecommendationRequest;
use App\Services\RecommendationService;
use App\Services\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function city(Request $request, WeatherService $weather): JsonResponse
    {
        $values = $request->validate(['city' => ['required', 'string', 'max:120']]);

        return response()->json(['data' => $weather->resolveCity($values['city'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(RecommendationRequest $request, RecommendationService $recommendations): JsonResponse
    {
        $values = $request->validated();

        return response()->json($recommendations->recommend($request->user(), (int) ($values['limit'] ?? 12), $values['latitude'] ?? null, $values['longitude'] ?? null))
            ->header('Cache-Control', 'private, no-store');
    }
}
