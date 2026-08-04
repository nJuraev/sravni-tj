<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BankCurrencyRate;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Общая логика «лучшего курса»: свежайший курс на банк, экстремумы по сторонам,
 * форматирование. Используется рассылкой алертов (DispatchRateAlerts) и ботом
 * (TelegramWebhookController «Курс валют»).
 *
 * Соглашение «лучшее для клиента»: банк ПОКУПАЕТ (buy) — выгоден максимум,
 * банк ПРОДАЁТ (sell) — выгоден минимум.
 */
class RateDigestService
{
    public const EPS = 0.00005;

    /** Валюты, показываемые в сводке бота (TJS — базовая, без курса). */
    public const BOT_CURRENCIES = ['USD', 'EUR'];

    /**
     * Последний курс каждого активного банка по category×currency
     * (одна строка на банк — самая свежая по rate_date), с именем банка.
     *
     * @return Collection<int, array{bank: string, buy: float|null, sell: float|null}>
     */
    public function latestRates(string $category, string $currency): Collection
    {
        return BankCurrencyRate::query()
            ->select(DB::raw('DISTINCT ON (bank_id) bank_currency_rates.*'))
            ->with('bank:id,name_ru')
            ->whereHas('bank', fn (Builder $b) => $b->where('status', 'active'))
            ->where('category', $category)
            ->where('currency', $currency)
            ->orderBy('bank_id')
            ->orderByDesc('rate_date')
            ->get()
            ->map(fn (BankCurrencyRate $row): array => [
                'bank' => (string) ($row->bank->name_ru ?? '—'),
                'buy' => $row->buy !== null ? (float) $row->buy : null,
                'sell' => $row->sell !== null ? (float) $row->sell : null,
            ]);
    }

    /**
     * Экстремум (max/min) по стороне side + все банки, котирующие этот курс.
     *
     * @param  Collection<int, array{bank: string, buy: float|null, sell: float|null}>  $rows
     * @return array{value: float|null, banks: array<int, string>}
     */
    public function extreme(Collection $rows, string $side, string $mode): array
    {
        $values = $rows->filter(fn (array $r): bool => $r[$side] !== null);

        if ($values->isEmpty()) {
            return ['value' => null, 'banks' => []];
        }

        $value = $mode === 'max'
            ? (float) $values->max(fn (array $r) => $r[$side])
            : (float) $values->min(fn (array $r) => $r[$side]);

        $banks = $values
            ->filter(fn (array $r): bool => abs($r[$side] - $value) < self::EPS)
            ->pluck('bank')
            ->unique()
            ->values()
            ->all();

        return ['value' => $value, 'banks' => $banks];
    }

    /**
     * @param  array{value: float|null, banks: array<int, string>}  $side
     */
    public function formatSide(array $side): string
    {
        if ($side['value'] === null) {
            return '—';
        }

        $banks = $side['banks'] === [] ? '' : ' — '.implode(', ', $side['banks']);

        return $this->formatNumber($side['value']).$banks;
    }

    public function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * Текст сводки для бота: лучший наличный курс по USD и EUR.
     */
    public function botCashSummary(): string
    {
        $lines = ['💱 Лучший курс наличных сейчас:'];

        foreach (self::BOT_CURRENCIES as $currency) {
            $rows = $this->latestRates('cash', $currency);
            $sell = $this->extreme($rows, 'sell', 'min');
            $buy = $this->extreme($rows, 'buy', 'max');

            $lines[] = '';
            $lines[] = $currency;

            if ($sell['value'] === null && $buy['value'] === null) {
                $lines[] = '  нет данных';

                continue;
            }

            $lines[] = '  Банк продаёт: '.$this->formatSide($sell);
            $lines[] = '  Банк покупает: '.$this->formatSide($buy);
        }

        return implode("\n", $lines);
    }
}
