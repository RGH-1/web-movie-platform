<?php

namespace App\Http\Controllers;

use App\Http\Resources\FollowUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowController extends Controller
{
    public function store(Request $request, string $userId)
    {
        return $this->update($request, $userId, true);
    }

    public function destroy(Request $request, string $userId)
    {
        return $this->update($request, $userId, false);
    }

    private function update(Request $request, string $userId, bool $follow): FollowUserResource
    {
        $target = $this->target($userId);
        $actor = $request->user();
        abort_if($actor->is($target), 422, 'You cannot follow yourself.');
        DB::transaction(function () use ($actor, $target, $follow) {
            // Serialize concurrent follow/unfollow requests from the same actor.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($follow) {
                $actor->following()->syncWithoutDetaching([$target->id]);
            } else {
                $actor->following()->detach($target->id);
            }
        }, 3);
        $target->loadCount(['followers', 'following']);
        $target->loadExists(['followers as is_following' => fn ($q) => $q->where('follows.follower_id', $actor->id)]);

        return new FollowUserResource($target);
    }

    public function followers(Request $request, string $userId)
    {
        return $this->listing($request, $userId, 'followers');
    }

    public function following(Request $request, string $userId)
    {
        return $this->listing($request, $userId, 'following');
    }

    private function listing(Request $request, string $userId, string $relationship)
    {
        $target = $this->target($userId);
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $viewer = $request->user('sanctum');
        $query = $target->{$relationship}()->select(['users.id', 'users.name'])->withCount(['followers', 'following'])
            ->withExists(['followers as is_following' => fn ($q) => $q->where('follows.follower_id', $viewer?->id ?? 0)])->orderBy('users.id');

        return FollowUserResource::collection($query->paginate($validated['per_page'] ?? 20)->withQueryString());
    }

    private function target(string $userId): User
    {
        $id = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false || (string) $id !== $userId, 404, 'User not found.');

        return User::findOrFail($id);
    }
}
