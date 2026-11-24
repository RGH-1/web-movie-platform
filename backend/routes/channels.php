<?php

use App\Models\MovieNight;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('movie-nights.{nightId}', function (User $user, string $nightId): bool {
    if (! ctype_digit($nightId)) {
        return false;
    }

    $night = MovieNight::find($nightId);

    return $night !== null && Gate::forUser($user)->allows('view', $night);
});
