<?php

namespace App\Services;

use App\Models\MovieNight;
use App\Models\MovieNightInvitation;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MovieNightAccessService
{
    public function create(
        User $actor,
        array $attributes,
        string $initialStatus = MovieNight::STATUS_DRAFT,
    ): MovieNight {
        Gate::forUser($actor)->authorize('create', MovieNight::class);

        $this->assertEditableAttributes($attributes);
        $this->assertManagedStatus($initialStatus);

        return DB::transaction(function () use (
            $actor,
            $attributes,
            $initialStatus,
        ): MovieNight {
            $night = new MovieNight($attributes);
            $night->organizer()->associate($actor);
            $night->status = $initialStatus;

            $this->assertFutureStart($night);

            $night->save();

            return $night->refresh();
        });
    }

    public function update(
        User $actor,
        MovieNight $night,
        array $attributes,
        ?string $requestedStatus = null,
    ): MovieNight {
        return $this->withPermission(
            $actor,
            $night,
            'update',
            function (MovieNight $current) use (
                $attributes,
                $requestedStatus,
            ): MovieNight {
                $this->assertEditableAttributes($attributes);

                if ($requestedStatus !== null) {
                    $this->assertManagedStatus($requestedStatus);
                }

                $current->fill($attributes);

                if ($requestedStatus !== null) {
                    $current->status = $requestedStatus;
                }

                $isScheduling =
                    $current->getRawOriginal('status') === MovieNight::STATUS_DRAFT
                    && $current->status === MovieNight::STATUS_SCHEDULED;

                if (array_key_exists('starts_at', $attributes) || $isScheduling) {
                    $this->assertFutureStart($current);
                }

                $current->save();

                return $current->refresh();
            },
        );
    }

    public function cancel(
        User $actor,
        MovieNight $night,
    ): MovieNight {
        return $this->withPermission(
            $actor,
            $night,
            'cancel',
            function (MovieNight $current): MovieNight {
                $current->status = MovieNight::STATUS_CANCELLED;
                $current->save();

                return $current->refresh();
            },
        );
    }

    public function forVote(
        User $actor,
        MovieNight $night,
        Closure $write,
    ): mixed {
        return $this->withPermission($actor, $night, 'vote', $write);
    }

    public function invite(User $actor, MovieNight $night, int $inviteeId): MovieNightInvitation
    {
        return $this->withPermission($actor, $night, 'invite', function (MovieNight $current) use ($actor, $inviteeId): MovieNightInvitation {
            if ($inviteeId === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['invitee_id' => 'The organizer is already part of this movie night.']);
            }

            if ($current->invitations()->where('invitee_id', $inviteeId)->exists()) {
                throw ValidationException::withMessages(['invitee_id' => 'This user has already been invited.']);
            }

            $invitation = new MovieNightInvitation;
            $invitation->invitee_id = $inviteeId;
            $invitation->inviter()->associate($actor);
            $current->invitations()->save($invitation);

            return $invitation;
        });
    }

    public function respondToInvitation(User $actor, MovieNight $night, string $status): MovieNightInvitation
    {
        return $this->withPermission($actor, $night, 'respondToInvitation', function (MovieNight $current) use ($actor, $status): MovieNightInvitation {
            $inviteeId = $actor->getKey();
            $invitation = $current->invitations()->where('invitee_id', $inviteeId)->firstOrFail();

            if ($status === MovieNightInvitation::STATUS_ACCEPTED) {
                $current->participants()->syncWithoutDetaching([$inviteeId]);
            } elseif ($invitation->status === MovieNightInvitation::STATUS_ACCEPTED) {
                $current->participants()->detach($inviteeId);
            }

            $invitation->status = $status;
            $invitation->save();

            return $invitation;
        });
    }

    public function forMessage(
        User $actor,
        MovieNight $night,
        Closure $write,
    ): mixed {
        return $this->withPermission($actor, $night, 'sendMessage', $write);
    }

    private function assertEditableAttributes(array $attributes): void
    {
        $unexpected = array_diff(
            array_keys($attributes),
            MovieNight::EDITABLE_FIELDS,
        );

        if ($unexpected !== []) {
            throw ValidationException::withMessages(
                array_fill_keys(
                    $unexpected,
                    ['This field cannot be updated.'],
                ),
            );
        }
    }

    private function assertManagedStatus(string $status): void
    {
        if (! in_array($status, [
            MovieNight::STATUS_DRAFT,
            MovieNight::STATUS_SCHEDULED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => [
                    'Use draft or scheduled. Cancellation has a dedicated action.',
                ],
            ]);
        }
    }

    private function assertFutureStart(MovieNight $night): void
    {
        if ($night->starts_at === null || ! $night->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'starts_at' => [
                    'The start time must be in the future.',
                ],
            ]);
        }
    }

    private function withPermission(
        User $actor,
        MovieNight $night,
        string $ability,
        Closure $write,
    ): mixed {
        return DB::transaction(function () use (
            $actor,
            $night,
            $ability,
            $write,
        ): mixed {
            $current = MovieNight::query()
                ->lockForUpdate()
                ->findOrFail($night->getKey());

            Gate::forUser($actor)->authorize($ability, $current);

            return $write($current);
        });
    }
}
