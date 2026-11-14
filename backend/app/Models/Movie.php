<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Movie extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'tmdb_id',
        'title',
        'overview',
        'release_date',
        'runtime_minutes',
        'genres',
        'poster_url',
        'backdrop_url',
        'director',
        'cast',
        'vote_average',
        'trailer',
        'metadata_overrides',
        'tmdb_synced_at',
        'deactivated_at',
    ];

    protected $attributes = [
        'genres' => '[]',
        'cast' => '[]',
        'metadata_overrides' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'tmdb_id' => 'integer',
            'release_date' => 'date:Y-m-d',
            'runtime_minutes' => 'integer',
            'genres' => 'array',
            'cast' => 'array',
            'vote_average' => 'float',
            'trailer' => 'array',
            'metadata_overrides' => 'array',
            'tmdb_synced_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Movie $movie): void {
            if ($movie->exists && $movie->isDirty(['source', 'tmdb_id'])) {
                throw ValidationException::withMessages([
                    'source' => ['Movie source and TMDB identity cannot be changed.'],
                ]);
            }

            $values = $movie->attributesToArray();
            foreach (['tmdb_id', 'runtime_minutes', 'vote_average'] as $field) {
                $values[$field] = $movie->getAttributes()[$field] ?? null;
            }
            if (is_string($movie->title)) {
                $movie->title = trim($movie->title);
                $values['title'] = $movie->title;
            }

            Validator::make($values, [
                'source' => ['required', Rule::in(['tmdb', 'local'])],
                'tmdb_id' => $movie->source === 'tmdb'
                    ? ['required', 'integer', 'between:1,2147483647']
                    : ['prohibited'],
                'title' => ['required', 'string', 'max:255'],
                'runtime_minutes' => ['nullable', 'integer', 'min:1'],
                'vote_average' => $movie->source === 'local'
                    ? ['prohibited'] : ['nullable', 'numeric', 'between:0,10'],
                'tmdb_synced_at' => $movie->source === 'local' ? ['prohibited'] : ['nullable', 'date'],
                'genres' => ['present', 'array', 'list'],
                'cast' => ['present', 'array', 'list'],
                'metadata_overrides' => ['present', 'array', 'list'],
            ])->validate();
        });
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'movie_id');
    }

    public function customLists(): BelongsToMany
    {
        return $this->belongsToMany(CustomList::class, 'list_movies', 'movie_id', 'list_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }



    public function userStatuses(): HasMany
    {
        return $this->hasMany(UserMovieStatus::class, 'movie_id');
    }
}
