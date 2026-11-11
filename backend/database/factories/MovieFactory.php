<?php

namespace Database\Factories;

use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Movie> */
class MovieFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source' => 'local',
            'tmdb_id' => null,
            'title' => fake()->sentence(3),
        ];
    }

    public function tmdb(?int $id = null): static
    {
        return $this->state(fn () => [
            'source' => 'tmdb',
            'tmdb_id' => $id ?? fake()->unique()->numberBetween(1, 2147483647),
            'tmdb_synced_at' => now(),
        ]);
    }
}
