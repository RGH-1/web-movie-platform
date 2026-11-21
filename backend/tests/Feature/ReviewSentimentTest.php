<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeReviewSentiment;
use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use App\Services\SentimentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReviewSentimentTest extends TestCase
{
    use RefreshDatabase;

    private const AZURE_ENDPOINT =
    'https://test-language.cognitiveservices.azure.com';

    private const AZURE_URL =
    'https://test-language.cognitiveservices.azure.com/language/:analyze-text?api-version=2024-11-01';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.azure_language.key',
            'test-key'
        );

        config()->set(
            'services.azure_language.endpoint',
            self::AZURE_ENDPOINT
        );

        // Never send real API requests during tests.
        Http::preventStrayRequests();
    }

    public function test_written_reviews_are_queued_and_completed_with_normalized_sentiment(): void
    {
        Queue::fake();

        Http::fake([
            self::AZURE_URL => Http::response(
                $this->azureResponse('positive'),
                200
            ),
        ]);

        $review = $this->createViaApi('I loved this movie.');

        $this->assertSame(
            Review::SENTIMENT_PENDING,
            $review->sentiment_status
        );

        Queue::assertPushed(AnalyzeReviewSentiment::class);

        $this->runJob($review);

        $review->refresh();

        $this->assertSame(
            Review::SENTIMENT_COMPLETED,
            $review->sentiment_status
        );

        $this->assertSame(
            'positive',
            $review->sentiment_label
        );

        $this->assertEqualsWithDelta(
            1.0,
            (float) $review->sentiment_score,
            0.0001
        );

        Http::assertSent(function ($request) {
            return $request->url() === self::AZURE_URL
                && $request->hasHeader(
                    'Ocp-Apim-Subscription-Key',
                    'test-key'
                )
                && data_get(
                    $request->data(),
                    'kind'
                ) === 'SentimentAnalysis'
                && data_get(
                    $request->data(),
                    'analysisInput.documents.0.text'
                ) === 'I loved this movie.'
                && data_get(
                    $request->data(),
                    'analysisInput.documents.0.language'
                ) === 'en';
        });

        Http::assertSentCount(1);
    }

    public function test_supported_negative_and_neutral_labels_are_persisted(): void
    {
        Http::fake([
            self::AZURE_URL => Http::sequence()
                ->push($this->azureResponse('negative'), 200)
                ->push($this->azureResponse('neutral'), 200),
        ]);

        foreach (
            [
                'negative' => -1.0,
                'neutral' => 0.0,
            ] as $label => $expectedScore
        ) {

            $review = $this->createReview(
                "A {$label} review."
            );

            $this->runJob($review);

            $review->refresh();

            $this->assertSame(
                Review::SENTIMENT_COMPLETED,
                $review->sentiment_status
            );

            $this->assertSame(
                $label,
                $review->sentiment_label
            );

            $this->assertEqualsWithDelta(
                $expectedScore,
                (float) $review->sentiment_score,
                0.0001
            );
        }
    }

    public function test_rating_only_reviews_are_skipped_and_never_queued(): void
    {
        Queue::fake();

        $review = $this->createViaApi(null);

        $this->assertSame(
            Review::SENTIMENT_SKIPPED,
            $review->sentiment_status
        );

        $this->assertNull($review->sentiment_label);

        Queue::assertNothingPushed();
    }

    public function test_editing_text_requeues_analysis_but_changing_only_rating_does_not(): void
    {
        $review = $this->createReview('First text.');

        Queue::fake();

        $this->actingAs($review->user, 'web')
            ->patchJson(
                "/api/v1/reviews/{$review->id}",
                ['rating' => 2]
            )
            ->assertOk();

        Queue::assertNothingPushed();

        $this->patchJson(
            "/api/v1/reviews/{$review->id}",
            ['review_text' => 'Changed text.']
        )
            ->assertOk()
            ->assertJsonPath(
                'data.sentiment_status',
                Review::SENTIMENT_PENDING
            )
            ->assertJsonPath(
                'data.sentiment_label',
                null
            );

        Queue::assertPushed(AnalyzeReviewSentiment::class);

        $this->assertNotSame(
            $review->sentiment_text_hash,
            $review->fresh()->sentiment_text_hash
        );
    }

    public function test_clearing_text_removes_stale_sentiment_and_marks_review_skipped(): void
    {
        $review = $this->createReview('Written text.', [
            'sentiment_label' => 'positive',
            'sentiment_status' => Review::SENTIMENT_COMPLETED,
            'sentiment_score' => 0.8,
        ]);

        Queue::fake();

        $this->actingAs($review->user, 'web')
            ->patchJson(
                "/api/v1/reviews/{$review->id}",
                ['review_text' => null]
            )
            ->assertOk()
            ->assertJsonPath(
                'data.sentiment_status',
                Review::SENTIMENT_SKIPPED
            )
            ->assertJsonPath('data.sentiment_label', null)
            ->assertJsonPath('data.sentiment_score', null);

        Queue::assertNothingPushed();
    }

    public function test_provider_failures_do_not_lose_reviews_and_mark_processing_failed(): void
    {
        $responses = [
            Http::response(['message' => 'quota exceeded'], 429),
            Http::response(['message' => 'unauthorized'], 401),
            Http::response(['message' => 'forbidden'], 403),
            Http::response(['message' => 'unavailable'], 500),
            Http::response(['unexpected' => 'payload'], 200),
            Http::response([
                'kind' => 'SentimentAnalysisResults',
                'results' => [
                    'documents' => [],
                    'errors' => [
                        [
                            'id' => '1',
                            'error' => [
                                'code' => 'InvalidDocument',
                                'message' => 'Invalid text.',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ];

        foreach ($responses as $response) {

            Http::fake([
                self::AZURE_URL => $response,
            ]);

            $review = $this->createReview(
                'Text that must remain saved.'
            );

            $this->runJob($review);

            $this->assertDatabaseHas('reviews', [
                'id' => $review->id,
                'review_text' => 'Text that must remain saved.',
                'sentiment_status' => Review::SENTIMENT_FAILED,
                'sentiment_label' => null,
                'sentiment_score' => null,
            ]);
        }
    }

    public function test_provider_timeout_marks_failed_without_losing_the_review(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Timed out');
        });

        $review = $this->createReview(
            'Network failure test.'
        );

        $this->runJob($review);

        $review->refresh();

        $this->assertSame(
            Review::SENTIMENT_FAILED,
            $review->sentiment_status
        );

        $this->assertSame(
            'Network failure test.',
            $review->review_text
        );

        $this->assertNull($review->sentiment_label);
    }

    public function test_stale_job_cannot_overwrite_a_newer_review_revision(): void
    {
        $review = $this->createReview('Old text.');

        $oldHash = $review->sentiment_text_hash;

        Queue::fake();

        $this->actingAs($review->user, 'web')
            ->patchJson(
                "/api/v1/reviews/{$review->id}",
                ['review_text' => 'New text.']
            )
            ->assertOk();

        $newHash = $review->fresh()->sentiment_text_hash;

        Http::fake([
            self::AZURE_URL => Http::response(
                $this->azureResponse('positive'),
                200
            ),
        ]);

        (new AnalyzeReviewSentiment(
            $review->id,
            $oldHash
        ))->handle(
            app(SentimentAnalysisService::class)
        );

        $review->refresh();

        $this->assertSame(
            $newHash,
            $review->sentiment_text_hash
        );

        $this->assertSame(
            Review::SENTIMENT_PENDING,
            $review->sentiment_status
        );

        $this->assertNull($review->sentiment_label);

        Http::assertNothingSent();
    }

    public function test_sentiment_fields_cannot_be_forged_and_resource_exposes_safe_fields(): void
    {
        $movie = Movie::factory()->create();
        $author = User::factory()->create();

        Queue::fake();

        $this->actingAs($author, 'web')
            ->postJson($this->movieUrl($movie), [
                'rating' => 5,
                'review_text' => 'Great.',
                'sentiment_label' => 'positive',
                'sentiment_status' => 'completed',
                'sentiment_score' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sentiment_label',
                'sentiment_status',
                'sentiment_score',
            ]);

        $review = $this->createReview(
            'Resource contract.'
        );

        $this->getJson(
            $this->movieUrl($review->movie)
        )
            ->assertOk()
            ->assertJsonPath(
                'data.0.sentiment_label',
                null
            )
            ->assertJsonPath(
                'data.0.sentiment_status',
                Review::SENTIMENT_PENDING
            )
            ->assertJsonPath(
                'data.0.sentiment_score',
                null
            );
    }

    /**
     * Generate a realistic mocked Azure response.
     */
    private function azureResponse(string $label): array
    {
        $scores = match ($label) {
            'positive' => [
                'positive' => 1.0,
                'neutral' => 0.0,
                'negative' => 0.0,
            ],
            'negative' => [
                'positive' => 0.0,
                'neutral' => 0.0,
                'negative' => 1.0,
            ],
            'neutral' => [
                'positive' => 0.0,
                'neutral' => 1.0,
                'negative' => 0.0,
            ],
            default => [
                'positive' => 0.0,
                'neutral' => 1.0,
                'negative' => 0.0,
            ],
        };

        return [
            'kind' => 'SentimentAnalysisResults',
            'results' => [
                'documents' => [
                    [
                        'id' => '1',
                        'sentiment' => $label,
                        'confidenceScores' => $scores,
                    ],
                ],
                'errors' => [],
            ],
        ];
    }

    private function createViaApi(?string $text): Review
    {
        $movie = Movie::factory()->create();
        $author = User::factory()->create();

        $body = ['rating' => 4];

        if ($text !== null) {
            $body['review_text'] = $text;
        }

        $this->actingAs($author, 'web')
            ->postJson($this->movieUrl($movie), $body)
            ->assertCreated();

        return Review::query()->sole();
    }

    private function createReview(
        string $text,
        array $attributes = []
    ): Review {
        $review = new Review([
            'rating' => 4,
            'review_text' => $text,
        ]);

        $review->user()->associate(
            User::factory()->create()
        );

        $review->movie()->associate(
            Movie::factory()->create()
        );

        $review->prepareSentimentClassification();

        $review->forceFill($attributes)->save();

        return $review->refresh();
    }

    private function runJob(Review $review): void
    {
        (new AnalyzeReviewSentiment(
            $review->id,
            $review->sentiment_text_hash
        ))->handle(
            app(SentimentAnalysisService::class)
        );
    }

    private function movieUrl(Movie $movie): string
    {
        return "/api/v1/catalog/movies/{$movie->id}/reviews";
    }
}
