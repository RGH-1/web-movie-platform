<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

class MovieNight extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_FINALIZED,
        self::STATUS_CANCELLED,
    ];

    public const EDITABLE_FIELDS = [
        'title',
        'description',
        'starts_at',
        'timezone',
        'location',
        'meeting_url',
    ];

    private const TRANSITIONS = [
        self::STATUS_DRAFT => [
            self::STATUS_SCHEDULED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_SCHEDULED => [
            self::STATUS_FINALIZED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_FINALIZED => [
            self::STATUS_CANCELLED,
        ],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = self::EDITABLE_FIELDS;

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'timezone' => 'UTC',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'organizer_id' => 'string',
            'winning_movie_id' => 'string',
            'starts_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MovieNight $night): void {
            if (is_string($night->title)) {
                $night->title = trim($night->title);
            }

            if (is_string($night->timezone)) {
                $night->timezone = trim($night->timezone);
            }

            foreach (['description', 'location', 'meeting_url'] as $field) {
                if (is_string($night->{$field})) {
                    $value = trim($night->{$field});
                    $night->{$field} = $value === '' ? null : $value;
                }
            }

            Validator::make($night->getAttributes(), [
                'organizer_id' => [
                    'required',
                    'integer',
                    'min:1',
                    Rule::exists('users', 'id'),
                ],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'starts_at' => [
                    'required',
                    'date_format:Y-m-d H:i:s',
                ],
                'timezone' => [
                    'required',
                    'string',
                    'max:64',
                    'timezone',
                ],
                'location' => ['nullable', 'string', 'max:255'],
                'meeting_url' => [
                    'nullable',
                    'string',
                    'max:2048',
                    'url:http,https',
                ],
                'status' => [
                    'required',
                    'string',
                    Rule::in(self::STATUSES),
                ],
                'winning_movie_id' => [
                    'nullable',
                    'integer',
                    'min:1',
                    Rule::exists('movies', 'id'),
                ],
            ])->validate();

            if ($night->exists && $night->isDirty('organizer_id')) {
                throw ValidationException::withMessages([
                    'organizer_id' => [
                        'The organizer cannot be changed.',
                    ],
                ]);
            }

            if ($night->exists && $night->isDirty('status')) {
                $originalStatus = $night->getRawOriginal('status');

                if (! in_array(
                    $night->status,
                    self::TRANSITIONS[$originalStatus] ?? [],
                    true,
                )) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'This status transition is not allowed.',
                        ],
                    ]);
                }
            }

            if ($night->status === self::STATUS_FINALIZED
                && $night->winning_movie_id === null) {
                throw ValidationException::withMessages([
                    'winning_movie_id' => [
                        'A finalized movie night requires a winning movie.',
                    ],
                ]);
            }

            if (in_array($night->status, [
                self::STATUS_DRAFT,
                self::STATUS_SCHEDULED,
            ], true) && $night->winning_movie_id !== null) {
                throw ValidationException::withMessages([
                    'winning_movie_id' => [
                        'Choose the winner when finalizing the movie night.',
                    ],
                ]);
            }

            if ($night->exists && $night->isDirty('winning_movie_id')) {
                $isFinalizing =
                    $night->getRawOriginal('status') === self::STATUS_SCHEDULED
                    && $night->status === self::STATUS_FINALIZED;

                if (! $isFinalizing) {
                    throw ValidationException::withMessages([
                        'winning_movie_id' => [
                            'The winner can only change during finalization.',
                        ],
                    ]);
                }
            }
        });
    }

    public function setStartsAtAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['starts_at'] = null;

            return;
        }

        if (! $value instanceof DateTimeInterface) {
            Validator::make(['starts_at' => $value], [
                'starts_at' => [
                    'bail',
                    'required',
                    'string',
                    'date',
                    'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
                ],
            ])->validate();
        }

        try {
            $instant = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'starts_at' => [
                    'Provide a valid date-time with an explicit UTC offset.',
                ],
            ]);
        }

        $this->attributes['starts_at'] = $instant
            ->utc()
            ->format('Y-m-d H:i:s');
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function winningMovie(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'winning_movie_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_SCHEDULED,
        ], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_SCHEDULED,
            self::STATUS_FINALIZED,
        ], true);
    }

    public function acceptsVotes(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function acceptsMessages(): bool
    {
        return in_array($this->status, [
            self::STATUS_SCHEDULED,
            self::STATUS_FINALIZED,
        ], true);
    }


    public function invitations(): HasMany
    {
        return $this->hasMany(MovieNightInvitation::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'movie_night_participants',
            'movie_night_id',
            'user_id',
        )->withTimestamps();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('movie_nights.organizer_id', $user->getKey())
                ->orWhere(function (Builder $participant) use ($user): void {
                    $participant
                        ->where('movie_nights.status', '!=', self::STATUS_DRAFT)
                        ->whereHas(
                            'participants',
                            fn (Builder $members): Builder => $members
                                ->whereKey($user->getKey()),
                        );
                });
        });
    }
}
