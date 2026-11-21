<?php

namespace App\Jobs;

use App\Models\Review;
use App\Services\SentimentAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AnalyzeReviewSentiment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $reviewId,
        public string $textHash,
    ) {}

    public function handle(SentimentAnalysisService $sentiment): void
    {
        $review = Review::find($this->reviewId);

        if ($review === null
            || $review->sentiment_status !== Review::SENTIMENT_PENDING
            || ! hash_equals(
                (string) $review->sentiment_text_hash,
                $this->textHash,
            )) {
            return;
        }

        try {
            $result = $sentiment->analyze($review->review_text);
        } catch (Throwable) {
            $this->markFailedIfCurrent();

            return;
        }

        // Check the revision in the UPDATE itself so a newer edit cannot
        // be overwritten between a separate check and save.
        $this->pendingRevision()->update([
            'sentiment_label' => $result['label'],
            'sentiment_score' => $result['score'],
            'sentiment_status' => Review::SENTIMENT_COMPLETED,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailedIfCurrent();
    }

    private function markFailedIfCurrent(): void
    {
        $this->pendingRevision()->update([
            'sentiment_label' => null,
            'sentiment_score' => null,
            'sentiment_status' => Review::SENTIMENT_FAILED,
        ]);
    }

    private function pendingRevision(): Builder
    {
        return Review::query()
            ->whereKey($this->reviewId)
            ->where('sentiment_status', Review::SENTIMENT_PENDING)
            ->where('sentiment_text_hash', $this->textHash);
    }
}