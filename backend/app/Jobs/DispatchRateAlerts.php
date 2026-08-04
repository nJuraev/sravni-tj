<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RateAlertSubscription;
use App\Services\RateDigestService;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Рассылка алертов по курсу — запускается после прогона Go-парсера курсов
 * (POST /api/internal/rates-notify → Api\Internal\RatesNotifyController).
 *
 * Условие отправки зависит от direction подписки:
 *   above — лучший (максимальный) курс по стороне op ≥ threshold;
 *   below — лучший (минимальный) курс по стороне op ≤ threshold.
 * И только если это значение изменилось с прошлого уведомления
 * (last_notified_value) — иначе каждый прогон парсера слал бы одно и то же.
 */
class DispatchRateAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TelegramService $telegram, RateDigestService $digest): void
    {
        $subscriptions = RateAlertSubscription::query()
            ->where('is_active', true)
            ->with('user')
            ->get();

        /** @var array<string, Collection<int, array{bank: string, buy: float|null, sell: float|null}>> $ratesCache */
        $ratesCache = [];

        foreach ($subscriptions as $subscription) {
            $key = "{$subscription->category}|{$subscription->currency}";
            $ratesCache[$key] ??= $digest->latestRates($subscription->category, $subscription->currency);
            $rows = $ratesCache[$key];

            if ($rows->isEmpty()) {
                continue;
            }

            $mode = $subscription->direction === 'above' ? 'max' : 'min';
            $value = $digest->extreme($rows, $subscription->op, $mode)['value'];

            if ($value === null) {
                continue;
            }

            $threshold = (float) $subscription->threshold;
            $meets = $subscription->direction === 'above' ? $value >= $threshold : $value <= $threshold;

            if (! $meets) {
                continue;
            }

            $lastValue = $subscription->last_notified_value !== null ? (float) $subscription->last_notified_value : null;

            if ($lastValue !== null && abs($lastValue - $value) < RateDigestService::EPS) {
                continue;
            }

            $user = $subscription->user;

            if ($user === null || $user->telegram_id === null) {
                continue;
            }

            try {
                $sent = $telegram->sendMessage((int) $user->telegram_id, $this->buildMessage($subscription, $rows, $digest));
            } catch (\Throwable $e) {
                Log::error('DispatchRateAlerts: failed to notify user.', [
                    'subscription_id' => $subscription->id,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            if ($sent) {
                $subscription->forceFill([
                    'last_notified_value' => $value,
                    'last_notified_at' => now(),
                ])->save();
            }
        }
    }

    /**
     * @param  Collection<int, array{bank: string, buy: float|null, sell: float|null}>  $rows
     */
    private function buildMessage(RateAlertSubscription $subscription, Collection $rows, RateDigestService $digest): string
    {
        $categoryLabel = $subscription->category === 'cash' ? 'обмен наличных' : 'денежные переводы';
        $opLabel = $subscription->op === 'buy' ? 'покупка' : 'продажа';
        $directionLabel = $subscription->direction === 'above' ? 'выше' : 'ниже';

        // Снапшот лучшего для клиента: банк покупает дороже (max buy),
        // банк продаёт дешевле (min sell).
        $sell = $digest->extreme($rows, 'sell', 'min');
        $buy = $digest->extreme($rows, 'buy', 'max');

        return "💱 {$subscription->currency} — {$categoryLabel}\n\n"
            ."Банк продаёт: {$digest->formatSide($sell)}\n"
            ."Банк покупает: {$digest->formatSide($buy)}\n\n"
            ."Сработал ваш алерт: {$opLabel} {$directionLabel} {$digest->formatNumber((float) $subscription->threshold)}";
    }
}
