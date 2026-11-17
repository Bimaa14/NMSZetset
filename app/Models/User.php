<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const USERNAME_REGEX_MESSAGE = 'Username hanya boleh berisi huruf, angka, titik (.), garis bawah (_), atau tanda hubung (-).';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'tenant_id',
        'role',
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferences'=>'array',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public static function normalizeUsername(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * @return list<mixed>
     */
    public static function usernameRules(?int $ignoreUserId = null): array
    {
        return [
            'required',
            'string',
            'min:2',
            'max:60',
            'regex:/^[a-z0-9._-]+$/',
            Rule::unique('users', 'username')->ignore($ignoreUserId),
        ];
    }

    protected function username(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : self::normalizeUsername($value),
        );
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, match ($this->role) {
            'isp_owner' => ['read', 'operate', 'manage', 'cli', 'maps', 'tags', 'staff', 'audit', 'api', 'subscription'],
            'noc' => ['read', 'operate', 'manage', 'cli', 'maps', 'tags'],
            'cs' => ['read'],
            'teknisi' => ['read', 'maps'],
            default => [],
        }, true);
    }
}
