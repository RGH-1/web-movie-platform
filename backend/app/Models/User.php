<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $attributes = [
        'is_admin' => false,
        'share_activity' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'is_admin',
        'disabled_at',
    ];

    /**
     * Required account fields only; optional preferences never gate access.
     * This is computed from current values rather than a stored status flag.
     *
     * @return list<string>
     */
    public function incompleteProfileFields(): array
    {
        $emailRules = $this->email === null && $this->socialAccounts()
            ->where('provider', 'facebook')
            ->exists()
            ? ['bail', 'nullable', 'string', 'max:255', 'email:rfc']
            : ['bail', 'required', 'string', 'max:255', 'email:rfc'];

        $validator = Validator::make([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'email' => is_string($this->email) ? trim($this->email) : $this->email,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => $emailRules,
        ]);

        return array_keys($validator->errors()->toArray());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'favorite_genres' => 'array',
            'share_activity' => 'boolean',
            'is_admin' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    public function revokeDatabaseSessions(): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table'))
                ->where('user_id', $this->getKey())->delete();
        }
    }

    public function movieLists(): HasMany
    {
        return $this->hasMany(MovieList::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'followed_id', 'follower_id')
            ->whereColumn('follows.follower_id', '!=', 'follows.followed_id')
            ->withTimestamps();
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'followed_id')
            ->whereColumn('follows.follower_id', '!=', 'follows.followed_id')
            ->withTimestamps();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function movieStatuses(): HasMany
    {
        return $this->hasMany(UserMovieStatus::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function organizedMovieNights(): HasMany
    {
        return $this->hasMany(MovieNight::class, 'organizer_id');
    }

    public function participatingMovieNights(): BelongsToMany
    {
        return $this->belongsToMany(
            MovieNight::class,
            'movie_night_participants',
            'user_id',
            'movie_night_id',
        )->withTimestamps();
    }
}
