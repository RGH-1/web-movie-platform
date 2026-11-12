<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    public function update(User $user, Review $review): bool
    {
        return $this->owns($user, $review);
    }

    public function delete(User $user, Review $review): bool
    {
        return $this->owns($user, $review);
    }

    private function owns(User $user, Review $review): bool
    {
        return (string) $user->getKey() === (string) $review->user_id;
    }
}
