<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Профиль пользователя, зарегистрированного через Telegram.
 * Без email/password/api_token — это внутренние поля идентификации.
 *
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'telegram_username' => $this->telegram_username,
            'created_at' => optional($this->created_at)->toIso8601ZuluString(),
        ];
    }
}
