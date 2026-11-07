
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TmdbIntegrationController;
use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\MovieSearchController;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Temporary endpoint for the TMDB integration test component
Route::get('/integration/tmdb/search', [
    TmdbIntegrationController::class,
    'search',
]);

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthSessionController::class, 'login'])
            ->middleware('throttle:login');

        Route::post('/logout', [AuthSessionController::class, 'logout'])
            ->middleware('auth:sanctum');
    });

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/me/profile', [ProfileController::class, 'show']);

        Route::patch('/me/profile', [ProfileController::class, 'update']);

        // AB#41 - Laravel movie search endpoint
        Route::get('/movies/search', [MovieSearchController::class, 'search'])
            ->middleware('profile.complete');

    });
});
