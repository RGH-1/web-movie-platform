<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SentimentAnalysisService
{
    /**
     * @return array{label: string, score: float|null}
     */
    public function analyze(string $text): array
    {
        $key = config('services.azure_language.key');
        $endpoint = config('services.azure_language.endpoint');

        if (
            ! is_string($key) || $key === '' ||
            ! is_string($endpoint) || $endpoint === ''
        ) {
            throw new RuntimeException('Sentiment analysis is not configured.');
        }

        $url = rtrim($endpoint, '/')
            . '/language/:analyze-text?api-version=2024-11-01';

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $key,
        ])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->post($url, [
                'kind' => 'SentimentAnalysis',
                'analysisInput' => [
                    'documents' => [
                        [
                            'id' => '1',
                            'language' => 'en',
                            'text' => $text,
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Sentiment provider request failed.');
        }

        $payload = $response->json();

        if (
            ! is_array($payload) ||
            ! empty($payload['results']['errors']) ||
            ! empty($payload['results']['documents'][0]['isError'])
        ) {
            throw new RuntimeException('Sentiment provider response was invalid.');
        }

        $document = $payload['results']['documents'][0] ?? null;

        $label = is_array($document)
            && is_string($document['sentiment'] ?? null)
            ? strtolower(trim($document['sentiment']))
            : null;
        if ($label === 'mixed') {
            $label = 'neutral';
        }
        if (! in_array($label, ['positive', 'neutral', 'negative'], true)) {
            throw new RuntimeException('Sentiment provider response was invalid.');
        }

        $scores = $document['confidenceScores'] ?? null;

        $score = null;

        if (
            is_array($scores) &&
            is_numeric($scores['positive'] ?? null) &&
            is_numeric($scores['negative'] ?? null)
        ) {
            $positive = (float) $scores['positive'];
            $negative = (float) $scores['negative'];

            if (
                is_finite($positive) &&
                is_finite($negative) &&
                $positive >= 0 && $positive <= 1 &&
                $negative >= 0 && $negative <= 1
            ) {
                $score = $positive - $negative;
            }
        }

        return [
            'label' => $label,
            'score' => $score,
        ];
    }
}
