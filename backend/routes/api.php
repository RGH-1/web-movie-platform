<?php

use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\LocalMovieDetailsController;
use App\Http\Controllers\MovieDetailsController;
use App\Http\Controllers\MovieFeedController;
use App\Http\Controllers\MovieSearchController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TmdbIntegrationController;
use App\Http\Middleware\RejectAuthenticatedRegistration;
use Illuminate\Support\Facades\Route;

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
        Route::post('/register', [RegistrationController::class, 'register'])->middleware(RejectAuthenticatedRegistration::class);
        Route::post('/login', [AuthSessionController::class, 'login'])->middleware('throttle:login');
        Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:passwords');
        Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:passwords');
    });

    Route::get('/movies/discover', [MovieFeedController::class, 'discover']);
    Route::get('/movies/genres', [MovieFeedController::class, 'genres']);
    Route::get('/movies/popular', [MovieFeedController::class, 'popular']);
    Route::get('/movies/upcoming', [MovieFeedController::class, 'upcoming']);
    Route::get('/catalog/movies/{movieId}/reviews', [ReviewController::class, 'index']);

    // Routes available to any authenticated account.
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthSessionController::class, 'logout']);
        Route::post('/auth/change-password', [PasswordController::class, 'change'])->middleware('throttle:passwords');

        Route::get('/me', [AuthSessionController::class, 'me']);
        Route::get('/me/profile', [ProfileController::class, 'show']);
        Route::patch('/me/profile', [ProfileController::class, 'update']);

        // Application features that also require a complete profile.
        Route::middleware('profile.complete')->group(function () {
            // AB#41 - Laravel movie search endpoint
            Route::get('/movies/search', [MovieSearchController::class, 'search']);

            Route::get('/catalog/movies/{movieId}', [LocalMovieDetailsController::class, 'show']);

            // AB#48 - Laravel movie details endpoint
            Route::get('/movies/{tmdbId}', [MovieDetailsController::class, 'show']);

            Route::post('/catalog/movies/{movieId}/reviews', [ReviewController::class, 'store']);
            Route::patch('/reviews/{review}', [ReviewController::class, 'update'])->can('update', 'review');
            Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->can('delete', 'review');
        });
    });
});
