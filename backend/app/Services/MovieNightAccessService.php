<?php

namespace App\Services;

use App\Models\MovieNight;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MovieNightAccessService
{
    public function update(
        User $actor,
        MovieNight $night,
        array $attributes,
    ): MovieNight {
        return $this->withPermission(
            $actor,
            $night,
            'update',
            function (MovieNight $current) use ($attributes): MovieNight {
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

                $current->fill($attributes)->save();

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

    public function forMessage(
        User $actor,
        MovieNight $night,
        Closure $write,
    ): mixed {
        return $this->withPermission($actor, $night, 'sendMessage', $write);
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