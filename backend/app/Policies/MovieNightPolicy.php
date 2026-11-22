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
        return $this->organizes($user, $night);
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