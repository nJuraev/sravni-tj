<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Снепшот текущих курсов валют для «дня курсов» (см. PostTopicSelector::WEEKLY_PATTERN).
 * Переиспользует RateDigestService (та же логика «лучшего курса», что и в
 * DispatchRateAlerts/боте). digestHtml() — готовый блок с цифрами для поста
 * в канал, собранный кодом (не LLM — см. RunFinancePostsScheduler).
 *
 * История курсов (динамика за месяц/квартал) — отдельная будущая задача,
 * bank_currency_rates сейчас хранит только последний снепшот на дату.
 */
class CurrencyRateSnapshotService
{
    public function __construct(private RateDigestService $digest) {}

    /**
     * @return array<int, array{currency: string, sell: array{value: float|null, banks: array<int, string>}, buy: array{value: float|null, banks: array<int, string>}}>
     */
    public function latestSnapshot(): array
    {
        $snapshot = [];

        foreach (RateDigestService::MAIN_CURRENCIES as $currency) {
            $rows = $this->digest->latestRates('cash', $currency);

            $snapshot[] = [
                'currency' => $currency,
                'sell' => $this->digest->extreme($rows, 'sell', 'min'),
                'buy' => $this->digest->extreme($rows, 'buy', 'max'),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<int, array{currency: string, sell: array{value: float|null, banks: array<int, string>}, buy: array{value: float|null, banks: array<int, string>}}>  $snapshot
     */
    public function hasData(array $snapshot): bool
    {
        foreach ($snapshot as $row) {
            if ($row['sell']['value'] !== null || $row['buy']['value'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Готовый HTML-блок с реальными цифрами (курс + банки) для поста в канал —
     * собирается кодом, не LLM, чтобы модель не могла «приукрасить» или
     * перепутать цифры между валютами.
     */
    public function digestHtml(): string
    {
        $blocks = $this->digest->currencyBlocksHtml('cash', RateDigestService::MAIN_CURRENCIES);

        return '💱 <b>Курс валют (наличные) сегодня</b>'."\n\n".implode("\n\n", $blocks);
    }
}
