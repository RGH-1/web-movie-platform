<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListMovieReviewsRequest;
use App\Http\Requests\WriteReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Jobs\AnalyzeReviewSentiment;
use App\Models\Movie;
use App\Models\Review;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReviewController extends Controller
{
    public function index(
        ListMovieReviewsRequest $request,
        string $movieId,
    ): AnonymousResourceCollection {
        $movie = Movie::query()->active()->findOrFail($this->movieKey($movieId));
        $visible = $movie->reviews()->where('moderation_status', Review::STATUS_VISIBLE);

        $stats = (clone $visible)->toBase()
            ->selectRaw('COUNT(*) AS rating_count, AVG(rating) AS rating_average')
            ->first();

        $ratingCount = (int) $stats->rating_count;

        $reviews = $visible
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: 10,
                page: (int) $request->validated('page', 1),
                total: $ratingCount,
            );

        return ReviewResource::collection($reviews)->additional([
            'meta' => [
                'rating_count' => $ratingCount,
                'rating_average' => $stats->rating_average === null
                    ? null
                    : round((float) $stats->rating_average, 2),
            ],
        ]);
    }

    public function store(WriteReviewRequest $request, string $movieId): JsonResponse
    {
        $id = $this->movieKey($movieId);

        try {
            [$review, $textHash] = DB::transaction(function () use ($request, $id): array {
                $movie = Movie::query()->active()->lockForUpdate()->findOrFail($id);

                $review = $movie->reviews()->make($request->validated());
                $review->user()->associate($request->user());
                $textHash = $review->prepareSentimentClassification();
                $review->save();

                return [$review, $textHash];
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'You already have a review for this movie.',
                'code' => 'REVIEW_ALREADY_EXISTS',
            ], 409);
        }

        $this->dispatchSentiment($review, $textHash);

        return (new ReviewResource($review->load('user:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(WriteReviewRequest $request, Review $review): ReviewResource
    {
        $attributes = $request->validated();
        $textChanged = array_key_exists('review_text', $attributes)
            && $attributes['review_text'] !== $review->review_text;

        $review->fill($attributes);
        $textHash = $textChanged ? $review->prepareSentimentClassification() : null;
        $review->save();

        if ($textChanged) {
            $this->dispatchSentiment($review, $textHash);
        }

        return new ReviewResource($review->refresh()->load('user:id,name'));
    }

    public function destroy(Review $review): Response
    {
        $review->delete();

        return response()->noContent();
    }

    private function movieKey(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false || (string) $id !== $value, 404, 'Movie not found.');

        return $id;
    }

    private function dispatchSentiment(Review $review, ?string $textHash): void
    {
        if ($textHash === null) {
            return;
        }

        try {
            AnalyzeReviewSentiment::dispatch($review->id, $textHash)
                ->onQueue('sentiment')
                ->afterCommit();
        } catch (Throwable) {
            // Queue failures must not turn an already-saved review into an API failure.
            $review->forceFill(['sentiment_status' => Review::SENTIMENT_FAILED])->saveQuietly();
        }
    }
}
