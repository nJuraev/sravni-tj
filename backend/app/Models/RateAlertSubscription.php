<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подписка пользователя на уведомление о курсе валюты (op — сторона
 * bank_currency_rates, с которой сравнивается threshold).
 *
 * @property int $id
 * @property int $user_id
 * @property string $category
 * @property string $currency
 * @property string $op
 * @property string $direction
 * @property string $threshold
 * @property string|null $last_notified_value
 * @property \Illuminate\Support\Carbon|null $last_notified_at
 * @property bool $is_active
 */
class RateAlertSubscription extends Model
{
    /** @use HasFactory<\Database\Factories\RateAlertSubscriptionFactory> */
    use HasFactory;

    protected $table = 'rate_alert_subscriptions';

    protected $fillable = [
        'user_id',
        'category',
        'currency',
        'op',
        'direction',
        'threshold',
    ];

    protected $casts = [
        'threshold' => 'decimal:4',
        'last_notified_value' => 'decimal:4',
        'last_notified_at' => 'datetime',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
