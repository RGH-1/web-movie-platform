<?php

namespace App\Policies;

use App\Models\MovieNight;
use App\Models\User;

class MovieNightPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, MovieNight $night): bool
    {
        if ($this->organizes($user, $night)) {
            return true;
        }

        return $night->status !== MovieNight::STATUS_DRAFT
            && $night->participants()
                ->whereKey($user->getKey())
                ->exists();
    }

    public function update(User $user, MovieNight $night): bool
    {
        return $this->organizes($user, $night)
            && $night->isEditable();
    }

    public function cancel(User $user, MovieNight $night): bool
    {
        return $this->organizes($user, $night)
            && $night->canBeCancelled();
    }

    public function vote(User $user, MovieNight $night): bool
    {
        return $this->organizes($user, $night)
            && $night->acceptsVotes();
    }

    public function invite(User $user, MovieNight $night): bool
    {
        return $this->organizes($user, $night)
            && $night->status !== MovieNight::STATUS_CANCELLED;
    }

    public function respondToInvitation(User $user, MovieNight $night): bool
    {
        return ! in_array($night->status, [MovieNight::STATUS_DRAFT, MovieNight::STATUS_CANCELLED], true)
            && $night->invitations()->where('invitee_id', $user->getKey())->exists();
    }

    public function viewAllInvitations(User $user, MovieNight $night): bool
    {
        return $this->organizes($user, $night);
    }

    public function sendMessage(User $user, MovieNight $night): bool
    {
        return $this->organizes($user, $night)
            && $night->acceptsMessages();
    }

    private function organizes(User $user, MovieNight $night): bool
    {
        return (string) $user->getKey() === (string) $night->organizer_id;
    }
}
