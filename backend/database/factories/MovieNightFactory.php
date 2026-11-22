<?php

namespace Database\Factories;

use App\Models\Movie;
use App\Models\MovieNight;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MovieNight> */
class MovieNightFactory extends Factory
{
    protected $model = MovieNight::class;

    public function definition(): array
    {
        return [
            'organizer_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => null,
            'starts_at' => now()->addWeek()->startOfMinute(),
            'timezone' => 'UTC',
            'location' => null,
            'meeting_url' => null,
            'status' => MovieNight::STATUS_DRAFT,
            'winning_movie_id' => null,
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => MovieNight::STATUS_SCHEDULED,
        ]);
    }

    public function finalized(): static
    {
        return $this->state(fn (): array => [
            'status' => MovieNight::STATUS_FINALIZED,
            'winning_movie_id' => Movie::factory(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => MovieNight::STATUS_CANCELLED,
        ]);
    }
}