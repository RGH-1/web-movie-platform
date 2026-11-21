<?php

namespace Tests\Unit;

use App\Services\ProviderAttemptBudget;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ProviderAttemptBudgetTest extends TestCase
{
    public function test_tmdb_and_weather_budgets_are_independent_and_bounded(): void
    {
        RateLimiter::clear('recommendations:provider:tmdb');
        RateLimiter::clear('recommendations:provider:weather');
        $budget = app(ProviderAttemptBudget::class);

        foreach (range(1, 120) as $_) {
            $this->assertTrue($budget->allowTmdb());
        }
        $this->assertFalse($budget->allowTmdb());
        $this->assertTrue($budget->allowWeather());
    }
}
