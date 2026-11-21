<?php

namespace App\Http\Controllers;

use App\Services\AdminStatisticsService;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(AdminStatisticsService $statistics): JsonResponse
    {
        return response()
            ->json(['data' => $statistics->dashboard()])
            ->header('Cache-Control', 'private, no-store');
    }
}
