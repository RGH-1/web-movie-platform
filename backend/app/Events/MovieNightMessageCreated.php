<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class MovieNightMessageCreated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    public function __construct(private readonly string $nightId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('movie-nights.'.$this->nightId);
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
        // History reads reauthorize the viewer, including after a participant is removed.
        return [];
    }
}
