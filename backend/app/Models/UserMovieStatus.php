<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMovieStatus extends Model
{
    protected $fillable = [
        'user_id',
        'movie_id',
        'in_watchlist',
        'watched_at',
    ];

    protected $attributes = [
        'in_watchlist' => false,
    ];

    protected function casts(): array
    {
        return [
            'in_watchlist' => 'boolean',
            'watched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function isWatched(): bool
    {
        return $this->watched_at !== null;
    }

    protected static function booted(): void
    {
        static::saved(function (UserMovieStatus $status): void {
            if (!$status->in_watchlist && $status->watched_at === null) {
                $status->delete();
            }
        });
    }
}
