<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MovieList extends Model
{
    protected $fillable = ['name', 'description', 'is_public'];

    protected $attributes = ['is_public' => false];

    protected function casts(): array
    {
        return ['id' => 'string', 'is_public' => 'boolean'];
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
