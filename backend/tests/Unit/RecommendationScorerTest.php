<?php

namespace Tests\Unit;

use App\Services\RecommendationScorer;
use Tests\TestCase;

class RecommendationScorerTest extends TestCase
{
    public function test_quality_is_always_active_and_optional_weights_redistribute(): void
    {
        $result = app(RecommendationScorer::class)->score($this->candidate(), $this->context());
        $this->assertSame(0.8, $result['signals']['quality']['value']);
        $this->assertSame(1.0, $result['signals']['quality']['weight']);
        $this->assertSame(80.0, $result['score']);
        $this->assertNull($result['signals']['weather']['value']);
    }

    public function test_weather_and_genre_scores_are_finite_and_weighted(): void
    {
        $context = $this->context();
        $context['favorites'] = ['mystery'];
        $context['weather'] = ['status' => 'available', 'condition' => 'rainy'];
        $candidate = $this->candidate();
        $candidate['genre_slugs'] = ['mystery'];
        $result = app(RecommendationScorer::class)->score($candidate, $context);
        $this->assertSame(1.0, $result['signals']['genre']['value']);
        $this->assertSame(1.0, $result['signals']['weather']['value']);
        $this->assertGreaterThan(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertEqualsWithDelta(1, array_sum(array_column($result['signals'], 'weight')), .0001);
    }

    public function test_documented_worked_example_inverts_only_when_weather_changes(): void
    {
        $scorer = app(RecommendationScorer::class);
        $a = $this->workedCandidate(['mystery'], 3.8, 7.6, [['tmdb_person_id' => 1, 'name' => 'A']], 'D2');
        $b = $this->workedCandidate(['science-fiction'], 4.0, 8.0, [['tmdb_person_id' => 1, 'name' => 'A']], 'D2');
        $context = [
            'favorites' => ['science-fiction', 'mystery'],
            'history' => ['science-fiction' => 1, 'mystery' => 1, 'comedy' => .5],
            'actor_affinity' => [1 => 1], 'director_affinity' => ['d1' => 1],
            'similarity_anchors' => [['genres' => ['science-fiction', 'mystery'], 'runtime_minutes' => 120, 'year' => 2020]],
            'weather' => ['status' => 'available', 'condition' => 'rainy'],
        ];
        $rainA = $scorer->score($a, $context);
        $rainB = $scorer->score($b, $context);
        $this->assertSame(72.2, $rainA['score']);
        $this->assertSame(67.0, $rainB['score']);
        $this->assertGreaterThan($rainB['score_raw'], $rainA['score_raw']);

        $context['weather']['condition'] = 'clear';
        $clearA = $scorer->score($a, $context);
        $clearB = $scorer->score($b, $context);
        $this->assertSame(66.2, $clearA['score']);
        $this->assertSame(69.0, $clearB['score']);
        $this->assertGreaterThan($clearA['score_raw'], $clearB['score_raw']);

        $context['weather'] = ['status' => 'unavailable'];
        $noneA = $scorer->score($a, $context);
        $noneB = $scorer->score($b, $context);
        $this->assertSame(69.11, $noneA['score']);
        $this->assertSame(70.0, $noneB['score']);
        $this->assertNull($noneA['signals']['weather']['value']);
    }

    private function workedCandidate(array $genres, float $average, float $voteAverage, array $cast, string $director): array
    {
        return [
            'genre_slugs' => $genres, 'platform_rating' => ['average' => $average, 'count' => 10], 'vote_average' => $voteAverage,
            'cast' => $cast, 'director' => $director, 'runtime_minutes' => 120, 'year' => 2020,
        ];
    }

    private function candidate(): array
    {
        return ['genre_slugs' => [], 'platform_rating' => ['average' => null, 'count' => 0], 'vote_average' => 8.0, 'cast' => [], 'director' => null, 'runtime_minutes' => null, 'year' => null];
    }

    private function context(): array
    {
        return ['favorites' => [], 'history' => [], 'actor_affinity' => [], 'director_affinity' => [], 'similarity_anchors' => [], 'weather' => ['status' => 'not_requested']];
    }
}
