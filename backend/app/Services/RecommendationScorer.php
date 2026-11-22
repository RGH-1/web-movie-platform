<?php

namespace App\Services;

class RecommendationScorer
{
    private const WEIGHTS = ['genre' => 25, 'quality' => 20, 'history' => 20, 'people' => 15, 'similarity' => 10, 'weather' => 10];

    /** @param array<string, mixed> $candidate @param array<string, mixed> $context @return array<string, mixed> */
    public function score(array $candidate, array $context): array
    {
        $genres = $candidate['genre_slugs'];
        $signals = [];
        $favorites = $context['favorites'];
        $signals['genre'] = $favorites === [] ? $this->inactive() : ($genres === [] ? $this->signal(.5, 'missing_metadata') : $this->signal(min(1, count(array_intersect($genres, $favorites)) / min(2, count($favorites))), 'observed'));

        $count = (int) $candidate['platform_rating']['count'];
        $average = $candidate['platform_rating']['average'];
        $prior = is_numeric($candidate['vote_average']) && $candidate['vote_average'] >= 0 && $candidate['vote_average'] <= 10 ? $candidate['vote_average'] / 10 : .6;
        $quality = $count === 0 ? $prior : (($count * ($average / 5)) + (5 * $prior)) / ($count + 5);
        $signals['quality'] = $this->signal($quality, $count > 0 ? 'observed' : 'prior');

        $history = $context['history'];
        $signals['history'] = $history === [] ? $this->inactive() : ($genres === [] ? $this->signal(.5, 'missing_metadata') : $this->signal(array_sum(array_map(fn (string $genre): float => $history[$genre] ?? 0, $genres)) / count($genres), 'observed'));

        $signals['people'] = $this->people($candidate, $context);
        $signals['similarity'] = $this->similarity($candidate, $context);
        $signals['weather'] = $this->weather($genres, $context['weather']);

        $activeWeight = array_sum(array_map(fn (string $key): int => $signals[$key]['value'] === null ? 0 : self::WEIGHTS[$key], array_keys(self::WEIGHTS)));
        $score = 0.0;
        foreach ($signals as $key => &$signal) {
            if ($signal['value'] === null) {
                $signal['weight'] = 0.0;
                $signal['points'] = 0.0;

                continue;
            }
            $signal['weight'] = self::WEIGHTS[$key] / $activeWeight;
            $signal['points'] = 100 * $signal['weight'] * $signal['value'];
            $score += $signal['points'];
        }

        return ['score_raw' => $score, 'score' => round($score, 2), 'signals' => collect($signals)->map(fn (array $signal) => ['value' => $signal['value'] === null ? null : round($signal['value'], 4), 'weight' => round($signal['weight'], 4), 'points' => round($signal['points'], 2), 'evidence' => $signal['evidence']])->all()];
    }

    /** @return array<string, mixed> */
    private function people(array $candidate, array $context): array
    {
        if ($context['actor_affinity'] === [] && $context['director_affinity'] === []) {
            return $this->inactive();
        }
        $values = [];
        if ($context['actor_affinity'] !== []) {
            $cast = $candidate['cast'];
            $values[] = $cast === [] ? .5 : max(array_map(fn (array $person): float => $context['actor_affinity'][$person['tmdb_person_id'] ?? strtolower($person['name'] ?? '')] ?? 0, $cast));
        }
        if ($context['director_affinity'] !== []) {
            $values[] = $candidate['director'] === null ? .5 : ($context['director_affinity'][strtolower(trim($candidate['director']))] ?? 0);
        }

        return $this->signal(array_sum($values) / count($values), 'observed');
    }

    /** @return array<string, mixed> */
    private function similarity(array $candidate, array $context): array
    {
        if ($context['similarity_anchors'] === []) {
            return $this->inactive();
        }
        if ($candidate['genre_slugs'] === []) {
            return $this->signal(.5, 'missing_metadata');
        }
        $best = 0.0;
        foreach ($context['similarity_anchors'] as $anchor) {
            $union = array_unique(array_merge($candidate['genre_slugs'], $anchor['genres']));
            $jaccard = $union === [] ? .5 : count(array_intersect($candidate['genre_slugs'], $anchor['genres'])) / count($union);
            $runtime = $candidate['runtime_minutes'] === null || $anchor['runtime_minutes'] === null ? .5 : max(0, 1 - abs($candidate['runtime_minutes'] - $anchor['runtime_minutes']) / 60);
            $year = $candidate['year'] === null || $anchor['year'] === null ? .5 : max(0, 1 - abs($candidate['year'] - $anchor['year']) / 20);
            $best = max($best, .6 * $jaccard + .2 * $runtime + .2 * $year);
        }

        return $this->signal($best, 'observed');
    }

    /** @return array<string, mixed> */
    private function weather(array $genres, array $weather): array
    {
        if (($weather['status'] ?? null) !== 'available') {
            return $this->inactive();
        }
        if ($genres === []) {
            return $this->signal(.5, 'missing_metadata');
        }
        $maps = [
            'rainy' => ['mystery' => 1, 'drama' => 1, 'romance' => .8, 'thriller' => .8, 'comedy' => .6, 'science-fiction' => .4],
            'snowy' => ['animation' => 1, 'fantasy' => .9, 'comedy' => .8, 'romance' => .8],
            'cold' => ['drama' => .9, 'romance' => .9, 'fantasy' => .8, 'animation' => .8],
            'hot' => ['comedy' => 1, 'adventure' => .9, 'animation' => .8, 'action' => .8],
            'clear' => ['adventure' => 1, 'animation' => .9, 'comedy' => .8, 'action' => .8, 'science-fiction' => .6, 'mystery' => .4],
            'cloudy' => ['mystery' => .8, 'drama' => .8, 'thriller' => .7],
        ];
        $map = $maps[$weather['condition']] ?? [];

        return $this->signal(array_sum(array_map(fn (string $genre): float => $map[$genre] ?? .5, $genres)) / count($genres), 'observed');
    }

    /** @return array{value: float|null, evidence: string, weight?: float, points?: float} */
    private function signal(float $value, string $evidence): array
    {
        return ['value' => max(0, min(1, $value)), 'evidence' => $evidence];
    }

    /** @return array{value: null, evidence: string, weight?: float, points?: float} */
    private function inactive(): array
    {
        return ['value' => null, 'evidence' => 'unavailable'];
    }
}
