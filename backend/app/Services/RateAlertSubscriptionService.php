<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RateAlertSubscription;
use App\Models\User;

/**
 * Правила создания алерта по курсу (лимит 3 активных, дубликат
 * category+currency+op+direction) — общие для веб-формы
 * (StoreRateAlertRequest) и мастера настройки в Telegram-боте
 * (TelegramWebhookController), чтобы не дублировать правила в двух местах.
 */
class RateAlertSubscriptionService
{
    public const MAX_ACTIVE_ALERTS = 3;

    public function hasReachedLimit(User $user): bool
    {
        return $user->rateAlertSubscriptions()->count() >= self::MAX_ACTIVE_ALERTS;
    }

    public function isDuplicate(User $user, string $category, string $currency, string $op, string $direction): bool
    {
        return $user->rateAlertSubscriptions()
            ->where('category', $category)
            ->where('currency', $currency)
            ->where('op', $op)
            ->where('direction', $direction)
            ->exists();
    }

    /**
     * @param  array{category: string, currency: string, op: string, direction: string, threshold: float|string}  $data
     */
    public function create(User $user, array $data): RateAlertSubscription
    {
        return $user->rateAlertSubscriptions()->create($data);
    }
}
