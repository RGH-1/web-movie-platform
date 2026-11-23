<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeReviewSentiment;
use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use App\Services\SentimentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ReviewSentimentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://s2-language.example.test';

    private const URL =
        self::ENDPOINT.'/language/:analyze-text?api-version=2024-11-01';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.azure_language.endpoint' => self::ENDPOINT,
            'services.azure_language.key' => 'fake-s2-key',
        ]);

        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_create_reclassify_clear_and_delete_workflow(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->providerResponse('positive'), 200)
                ->push($this->providerResponse('negative'), 200),
        ]);

        $review = $this->createWrittenReview();

        $this->assertPublicState($review, 'pending', null);

        $this->runJob($review);
        $this->assertPublicState($review, 'completed', 'positive');

        // Changing only stars preserves the existing text classification.
        $this->patchJson("/api/v1/reviews/{$review->id}", [
            'rating' => 1,
        ])->assertOk()
            ->assertJsonPath('data.rating', 1)
            ->assertJsonPath('data.sentiment_label', 'positive');

        Queue::assertPushed(AnalyzeReviewSentiment::class, 1);

        $this->patchJson("/api/v1/reviews/{$review->id}", [
            'review_text' => 'Changed written opinion.',
        ])->assertOk()
            ->assertJsonPath('data.sentiment_status', 'pending')
            ->assertJsonPath('data.sentiment_label', null)
            ->assertJsonPath('data.sentiment_score', null);

        $this->assertPublicState($review, 'pending', null);

        $this->runJob($review);
        $this->assertPublicState($review, 'completed', 'negative');

        $this->getJson("/api/v1/users/{$review->user_id}/profile")
            ->assertOk()
            ->assertJsonPath(
                'data.reviews.0.sentiment_label',
                'negative',
            );

        Queue::assertPushed(AnalyzeReviewSentiment::class, 2);
        Http::assertSentCount(2);

        $this->patchJson("/api/v1/reviews/{$review->id}", [
            'review_text' => null,
        ])->assertOk()
            ->assertJsonPath('data.review_text', null)
            ->assertJsonPath('data.sentiment_status', 'skipped')
            ->assertJsonPath('data.sentiment_label', null)
            ->assertJsonPath('data.sentiment_score', null);

        $this->assertPublicState($review, 'skipped', null);
        Queue::assertPushed(AnalyzeReviewSentiment::class, 2);

        $movieId = $review->movie_id;

        $this->deleteJson("/api/v1/reviews/{$review->id}")
            ->assertNoContent();

        $this->getJson("/api/v1/catalog/movies/{$movieId}/reviews")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.rating_count', 0)
            ->assertJsonPath('meta.rating_average', null);
    }

    public function test_provider_failure_preserves_review_and_allows_editing(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push(['message' => 'Simulated provider failure'], 503)
                ->push($this->providerResponse('positive'), 200),
        ]);

        $review = $this->createWrittenReview();

        $this->runJob($review);
        $this->assertPublicState($review, 'failed', null);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'rating' => 4,
            'review_text' => 'Initial written review.',
        ]);

        $this->patchJson("/api/v1/reviews/{$review->id}", [
            'review_text' => 'A revised written review.',
        ])->assertOk()
            ->assertJsonPath('data.sentiment_status', 'pending')
            ->assertJsonPath('data.sentiment_label', null);

        $this->runJob($review);
        $this->assertPublicState($review, 'completed', 'positive');

        Queue::assertPushed(AnalyzeReviewSentiment::class, 2);
    }

    public function test_failure_callback_only_changes_current_pending_revision(): void
    {
        $review = $this->createWrittenReview();
        $oldJob = new AnalyzeReviewSentiment(
            $review->id,
            $review->sentiment_text_hash,
        );

        $this->patchJson("/api/v1/reviews/{$review->id}", [
            'review_text' => 'A newer revision.',
        ])->assertOk();

        $oldJob->failed(new RuntimeException('Old worker failure'));

        $this->assertPublicState($review, 'pending', null);

        $review->refresh();

        $currentJob = new AnalyzeReviewSentiment(
            $review->id,
            $review->sentiment_text_hash,
        );

        $currentJob->failed(new RuntimeException('Current worker failure'));

        $this->assertPublicState($review, 'failed', null);
        Http::assertNothingSent();
    }

    private function createWrittenReview(): Review
    {
        $movie = Movie::factory()->create();
        $author = User::factory()->sharingAllActivity()->create();

        $id = $this->actingAs($author, 'web')
            ->postJson(
                "/api/v1/catalog/movies/{$movie->id}/reviews",
                [
                    'rating' => 4,
                    'review_text' => 'Initial written review.',
                ],
            )
            ->assertCreated()
            ->assertJsonPath('data.sentiment_status', 'pending')
            ->json('data.id');

        return Review::findOrFail($id);
    }

    private function runJob(Review $review): void
    {
        $review->refresh();

        (new AnalyzeReviewSentiment(
            $review->id,
            $review->sentiment_text_hash,
        ))->handle(app(SentimentAnalysisService::class));
    }

    private function assertPublicState(
        Review $review,
        string $status,
        ?string $label,
    ): void {
        $this->getJson(
            "/api/v1/catalog/movies/{$review->movie_id}/reviews"
        )->assertOk()
            ->assertJsonPath('data.0.id', (string) $review->id)
            ->assertJsonPath('data.0.sentiment_status', $status)
            ->assertJsonPath('data.0.sentiment_label', $label)
            ->assertJsonMissingPath('data.0.sentiment_text_hash')
            ->assertJsonMissingPath('data.0.moderation_reason');
    }

    private function providerResponse(string $label): array
    {
        return [
            'kind' => 'SentimentAnalysisResults',
            'results' => [
                'documents' => [[
                    'id' => '1',
                    'sentiment' => $label,
                    'confidenceScores' => [
                        'positive' => $label === 'positive' ? 0.9 : 0.05,
                        'neutral' => 0.05,
                        'negative' => $label === 'negative' ? 0.9 : 0.05,
                    ],
                ]],
                'errors' => [],
            ],
        ];
    }
}