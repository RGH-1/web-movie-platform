<?php

namespace App\Http\Controllers;

use App\Http\Resources\PublicProfileResource;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;

class PublicProfileController extends Controller
{
    public function __invoke(Request $request, string $userId): PublicProfileResource
    {
        $id = filter_var($userId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        abort_if($id === false || (string) $id !== $userId, 404, 'Profile not found.');

        $user = User::query()->find($id, ['id', 'name']);

        abort_if($user === null, 404, 'Profile not found.');

        $user->loadCount(['followers', 'following']);
        $user->loadExists(['followers as is_following' => fn ($query) => $query->where('follows.follower_id', $request->user('sanctum')?->id ?? 0)]);

        $visibleReviews = $user->reviews()
            ->where('moderation_status', Review::STATUS_VISIBLE)
            ->whereHas('movie', fn ($query) => $query->active());
        $publicLists = $user->movieLists()->where('is_public', true);

        $user->setAttribute('public_reviews_count', (clone $visibleReviews)->count());
        $user->setAttribute('public_lists_count', (clone $publicLists)->count());
        $user->setRelation('publicReviews', $visibleReviews
            ->with('user:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get());
        $user->setRelation('publicLists', $publicLists
            ->withCount('movies')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get());

        return new PublicProfileResource($user);
    }
}
