<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Пользователь админ-панели. Аутентификация — встроенный token-guard
 * (guard `admin`, см. config/auth.php): api_token проверяется по заголовку
 * Authorization: Bearer.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role
 * @property bool $is_active
 * @property string|null $api_token
 */
class AdminUser extends Authenticatable
{
    protected $table = 'admin_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'api_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'password' => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** Полные права (управление пользователями) только у роли admin. */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Только активные аккаунты (is_active = true).
     *
     * @param  Builder<AdminUser>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
