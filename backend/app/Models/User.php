<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
    }

    public function movieLists(): HasMany
    {
        return $this->hasMany(MovieList::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_follows', 'followed_id', 'follower_id')
            ->whereColumn('user_follows.follower_id', '!=', 'user_follows.followed_id')
            ->withTimestamps();
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'user_follows', 'follower_id', 'followed_id')
            ->whereColumn('user_follows.follower_id', '!=', 'user_follows.followed_id')
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
}
