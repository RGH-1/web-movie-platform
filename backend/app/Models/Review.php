<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;

class Review extends Model
{
    public const STATUS_VISIBLE = 'visible';
    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = [
        'rating',
        'review_text',
    ];

    protected $attributes = [
        'moderation_status' => self::STATUS_VISIBLE,
    ];

    protected function casts(): array
    {
        return [
            'movie_id' => 'string',
            'rating' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Review $review): void {
            Validator::make(
                ['rating' => $review->getAttributes()['rating'] ?? null],
                ['rating' => ['required', 'integer', 'between:1,5']],
            )->validate();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
}
