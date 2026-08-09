<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Снепшот текущих курсов валют для «дня курсов» (см. PostTopicSelector::WEEKLY_PATTERN).
 * Переиспользует RateDigestService (та же логика «лучшего курса», что и в
 * DispatchRateAlerts/боте) — здесь только сборка структуры и текста для LLM-промпта.
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

        foreach (RateDigestService::BOT_CURRENCIES as $currency) {
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
     * Реальные цифры в текстовом виде для LLM-промпта — без права «придумать» курс.
     *
     * @param  array<int, array{currency: string, sell: array{value: float|null, banks: array<int, string>}, buy: array{value: float|null, banks: array<int, string>}}>  $snapshot
     */
    public function toPromptContext(array $snapshot): string
    {
        $lines = ['Актуальные курсы валют (наличные) по данным банков Таджикистана на сегодня:'];

        foreach ($snapshot as $row) {
            $lines[] = "{$row['currency']}: банк продаёт — {$this->digest->formatSide($row['sell'])}, банк покупает — {$this->digest->formatSide($row['buy'])}";
        }

        return implode("\n", $lines);
    }
}
