<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        $validator = Validator::make([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'email' => is_string($this->email) ? trim($this->email) : $this->email,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'max:255', 'email:rfc'],
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


    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
