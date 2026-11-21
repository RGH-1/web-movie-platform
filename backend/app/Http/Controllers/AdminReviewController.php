<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListAdminReviewsRequest;
use App\Http\Requests\ModerateReviewRequest;
use App\Http\Resources\AdminReviewResource;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminReviewController extends Controller
{
    private const RELATIONS = [
        'user:id,name',
        'movie:id,title,source,tmdb_id,deactivated_at',
        'moderator:id,name',
    ];

    public function index(ListAdminReviewsRequest $request): JsonResponse
    {
        $query = Review::query()
            ->with(self::RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $status = $request->validated('status');

        if ($status !== null) {
            $query->where('moderation_status', $status);
        }

        $reviews = $query->paginate(
            perPage: (int) $request->validated('per_page', 20),
            page: (int) $request->validated('page', 1),
        )->withQueryString();

        return AdminReviewResource::collection($reviews)
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(
        ModerateReviewRequest $request,
        Review $review,
    ): JsonResponse {
        $updated = DB::transaction(function () use ($request, $review): Review {
            $current = Review::query()
                ->lockForUpdate()
                ->findOrFail($review->getKey());

            $status = $request->validated('status');

            if ($current->moderation_status !== $status) {
                $current->forceFill([
                    'moderation_status' => $status,
                    'moderated_by' => $request->user()->getKey(),
                    'moderated_at' => now()->startOfSecond(),
                    'moderation_reason' => $request->validated('reason'),
                ])->save();
            }

            return $current->load(self::RELATIONS);
        });

        return (new AdminReviewResource($updated))
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }
}