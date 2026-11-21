<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared outbound-provider budgets.  This deliberately sits below endpoint
 * throttling: a cache hit never calls these methods, while every attempted
 * upstream request (including a failed one) does.
 *
 * The configured production cache driver must be shared and atomic (for
 * example Redis) for this application-wide limit to span PHP workers.
 */
class ProviderAttemptBudget
{
    public function allowTmdb(): bool
    {
        return $this->allow('recommendations:provider:tmdb', 120);
    }

    public function allowWeather(): bool
    {
        return $this->allow('recommendations:provider:weather', 60);
    }

    private function allow(string $key, int $maximum): bool
    {
        return RateLimiter::attempt($key, $maximum, static fn (): bool => true, 60);
    }
}
