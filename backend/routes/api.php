<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TmdbIntegrationController;
use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationController;
use App\Http\Middleware\RejectAuthenticatedRegistration;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

//temporary endpoint for the TMDB integration test component
Route::get('/integration/tmdb/search', [
    TmdbIntegrationController::class,
    'search',
]);


Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register', [RegistrationController::class, 'register'])->middleware(RejectAuthenticatedRegistration::class);
        Route::post('/login', [AuthSessionController::class, 'login'])->middleware('throttle:login');
        Route::post('/logout', [AuthSessionController::class, 'logout'])->middleware('auth:sanctum');
    });


    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me/profile', [ProfileController::class, 'show']);
        Route::patch('/me/profile', [ProfileController::class, 'update']);
    });
});
