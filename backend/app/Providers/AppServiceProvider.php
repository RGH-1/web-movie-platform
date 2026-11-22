<?php

namespace App\Providers;

use App\Models\Review;
use App\Models\User;
use App\Models\MovieNight;
use App\Policies\MovieNightPolicy;
use App\Policies\ReviewPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define(
            'access-admin',
            static fn (User $user): bool => $user->is_admin === true,
        );

        Gate::policy(Review::class, ReviewPolicy::class);
        Gate::policy(MovieNight::class, MovieNightPolicy::class);

        ResetPassword::createUrlUsing(
            fn ($user, string $token) => config('auth.password_reset_url').'?'.http_build_query([
                'token' => $token, 'email' => $user->getEmailForPasswordReset(),
            ])
        );
        RateLimiter::for('passwords', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('recommendations', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id ?: $request->ip()));

        Route::bind('review', static function (string $value): Review {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($id === false || (string) $id !== $value, 404, 'Review not found.');

            return Review::query()->findOrFail($id);
        });

        Route::bind('movieNight', static function (string $value): MovieNight {
            $id = filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );

            abort_if(
                $id === false || (string) $id !== $value,
                404,
                'Movie night not found.',
            );

            $actor = request()->user();

            abort_if($actor === null, 401, 'Unauthenticated.');

            $night = MovieNight::query()
                ->visibleTo($actor)
                ->find($id);

            abort_if($night === null, 404, 'Movie night not found.');

            return $night;
        });
    }
}
