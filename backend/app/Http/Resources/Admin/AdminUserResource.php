<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Представление пользователя админки. Пароль и токен не отдаются.
 *
 * @mixin AdminUser
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601ZuluString(),
            'updated_at' => optional($this->updated_at)->toIso8601ZuluString(),
        ];
    }
}
