<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

class MovieList extends Model
{
    protected $fillable = ['name', 'description', 'is_public'];

    protected $attributes = ['is_public' => false];

    protected function casts(): array
    {
        return ['id' => 'string', 'is_public' => 'boolean', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (MovieList $list): void {
            if ($list->isDirty('is_public')) {
                $list->published_at = $list->is_public ? now() : null;
            }
        });
    }

    public function scopeSharedActivity(Builder $query): Builder
    {
        return $query
            ->where('movie_lists.is_public', true)
            ->whereHas(
                'user',
                fn (Builder $users): Builder => $users
                    ->sharingActivity('public_list'),
            );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'movie_list_items')->withTimestamps();
    }
}
