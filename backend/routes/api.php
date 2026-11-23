<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminMovieController;
use App\Http\Controllers\AdminReviewController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\CatalogMovieController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\FollowingFeedController;
use App\Http\Controllers\LocalMovieDetailsController;
use App\Http\Controllers\MovieDetailsController;
use App\Http\Controllers\MovieFeedController;
use App\Http\Controllers\MovieListController;
use App\Http\Controllers\MovieSearchController;
use App\Http\Controllers\NearbyCinemaController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ResolveTmdbMovieController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TmdbIntegrationController;
use App\Http\Controllers\UserMovieStatusController;
use App\Http\Middleware\RejectAuthenticatedRegistration;
use App\Http\Controllers\MovieNightController;
use App\Http\Controllers\MovieNightInvitationController;
use App\Http\Controllers\MovieNightVotingController;
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
    Route::get('/users/{userId}/profile', PublicProfileController::class);

    Route::get('/users/{userId}/followers', [FollowController::class, 'followers']);
    Route::get('/users/{userId}/following', [FollowController::class, 'following']);

    // Routes available to any authenticated account.
    Route::middleware(['auth:sanctum', 'account.enabled'])->group(function () {
        Route::get('/feed', FollowingFeedController::class);
        Route::get('/cinemas/nearby', NearbyCinemaController::class)->middleware('throttle:10,1,nearby-cinemas');

        Route::post('/users/{userId}/follow', [FollowController::class, 'store']);
        Route::delete('/users/{userId}/follow', [FollowController::class, 'destroy']);

        Route::post('/auth/logout', [AuthSessionController::class, 'logout']);
        Route::post('/auth/change-password', [PasswordController::class, 'change'])->middleware('throttle:passwords');

        Route::get('/me/lists', [MovieListController::class, 'index']);
        Route::get('/lists/public', [MovieListController::class, 'publicIndex']);
        Route::get('/lists/{listId}', [MovieListController::class, 'show']);

        Route::get('/me', [AuthSessionController::class, 'me']);
        Route::get('/me/profile', [ProfileController::class, 'show']);
        Route::patch('/me/profile', [ProfileController::class, 'update']);

        Route::prefix('admin')->middleware('can:access-admin')->group(function () {
            Route::get('/movies', [AdminMovieController::class, 'index']);
            Route::post('/movies', [AdminMovieController::class, 'store']);
            Route::get('/movies/{movieId}', [AdminMovieController::class, 'show']);
            Route::patch('/movies/{movieId}', [AdminMovieController::class, 'update']);
            Route::delete('/movies/{movieId}', [AdminMovieController::class, 'destroy']);
            Route::get('/dashboard', AdminDashboardController::class);
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::patch('/users/{userId}/status', [AdminUserController::class, 'update']);
            Route::get('/reviews', [AdminReviewController::class, 'index']);
            Route::patch('/reviews/{review}/moderation', [AdminReviewController::class, 'update']);
        });

        // Application features that also require a complete profile.
        Route::middleware('profile.complete')->group(function () {
            Route::get('/recommendations', [RecommendationController::class, 'index'])->middleware('throttle:recommendations');
            Route::get('/recommendations/city', [RecommendationController::class, 'city'])->middleware('throttle:10,1,recommendation-city');
            Route::post('/lists', [MovieListController::class, 'store']);
            Route::patch('/lists/{listId}', [MovieListController::class, 'update']);
            Route::delete('/lists/{listId}', [MovieListController::class, 'destroy']);
            Route::post('/lists/{listId}/movies', [MovieListController::class, 'addMovie']);
            Route::delete('/lists/{listId}/movies/{movieId}', [MovieListController::class, 'removeMovie']);

            // AB#41 - Laravel movie search endpoint
            Route::get('/movies/search', [MovieSearchController::class, 'search']);

            Route::get('/catalog/movies/{movieId}', [LocalMovieDetailsController::class, 'show']);
            Route::get('/catalog/movies', [CatalogMovieController::class, 'index']);
            Route::post('/movies/tmdb/{tmdbId}/resolve', ResolveTmdbMovieController::class);

            // AB#48 - Laravel movie details endpoint
            Route::get('/movies/{tmdbId}', [MovieDetailsController::class, 'show']);

            Route::post('/catalog/movies/{movieId}/reviews', [ReviewController::class, 'store']);
            Route::patch('/reviews/{review}', [ReviewController::class, 'update'])->can('update', 'review');
            Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->can('delete', 'review');

            Route::get('/me/movies', [UserMovieStatusController::class, 'index']);

            Route::get('/me/movie-night-invitations', [MovieNightInvitationController::class, 'inbox']);

            Route::get('/me/movies/{movieId}/status', [UserMovieStatusController::class, 'show']);

            Route::patch('/me/movies/{movieId}/status', [UserMovieStatusController::class, 'update']);


            Route::get('/movie-nights', [MovieNightController::class, 'index']);
            Route::post('/movie-nights', [MovieNightController::class, 'store']);
            Route::get('/movie-nights/{movieNight}',[MovieNightController::class, 'show']);
            Route::patch('/movie-nights/{movieNight}',[MovieNightController::class, 'update']);
            Route::post('/movie-nights/{movieNight}/cancel',[MovieNightController::class, 'cancel']);
            Route::get('/movie-nights/{movieNight}/candidates', [MovieNightVotingController::class, 'index']);
            Route::post('/movie-nights/{movieNight}/candidates', [MovieNightVotingController::class, 'store']);
            Route::put('/movie-nights/{movieNight}/vote', [MovieNightVotingController::class, 'vote']);
            Route::post('/movie-nights/{movieNight}/finalize', [MovieNightVotingController::class, 'finalize']);
            Route::post('/movie-nights/{movieNight}/invitations', [MovieNightInvitationController::class, 'store']);
            Route::get('/movie-nights/{movieNight}/invitations', [MovieNightInvitationController::class, 'index'])
                ->name('movie-nights.invitations.index');
            Route::patch('/movie-nights/{movieNight}/rsvp', [MovieNightInvitationController::class, 'respond'])
                ->name('movie-nights.rsvp');
        });
    });
});
