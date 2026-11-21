<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecommendationRequest;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;

class RecommendationController extends Controller
{
    public function index(RecommendationRequest $request, RecommendationService $recommendations): JsonResponse
    {
        $values = $request->validated();

        return response()->json($recommendations->recommend($request->user(), (int) ($values['limit'] ?? 12), $values['latitude'] ?? null, $values['longitude'] ?? null))
            ->header('Cache-Control', 'private, no-store');
    }
}
