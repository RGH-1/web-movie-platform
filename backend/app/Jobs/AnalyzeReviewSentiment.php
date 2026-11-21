<?php

namespace App\Jobs;

use App\Models\Review;
use App\Services\SentimentAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AnalyzeReviewSentiment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public int $reviewId,
        public string $textHash,
    ) {}

    public function handle(SentimentAnalysisService $sentiment): void
    {
        $review = Review::find($this->reviewId);

        if ($review === null || $review->sentiment_status !== Review::SENTIMENT_PENDING ||
            ! hash_equals((string) $review->sentiment_text_hash, $this->textHash)) {
            return;
        }

        try {
            $result = $sentiment->analyze($review->review_text);
        } catch (Throwable) {
            $this->markFailedIfCurrent();

            return;
        }

        $current = Review::find($this->reviewId);
        if ($current === null || ! hash_equals((string) $current->sentiment_text_hash, $this->textHash)) {
            return;
        }

        $current->forceFill([
            'sentiment_label' => $result['label'],
            'sentiment_score' => $result['score'],
            'sentiment_status' => Review::SENTIMENT_COMPLETED,
        ])->saveQuietly();
    }

    private function markFailedIfCurrent(): void
    {
        $review = Review::find($this->reviewId);
        if ($review !== null && $review->sentiment_status === Review::SENTIMENT_PENDING &&
            hash_equals((string) $review->sentiment_text_hash, $this->textHash)) {
            $review->forceFill(['sentiment_status' => Review::SENTIMENT_FAILED])->saveQuietly();
        }
    }
}
