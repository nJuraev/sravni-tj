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

    /** Валюты, которые интересуют большинство — показываются в боте сразу, без "Другие валюты". */
    public const MAIN_CURRENCIES = ['USD', 'EUR', 'RUB'];

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
     * Валюты, реально котируемые активными банками по категории (сортировка
     * алфавитная) — источник опций для мастера настройки алерта в боте, не
     * хардкод (в реальных данных, помимо BOT_CURRENCIES, есть и RUB).
     *
     * @return array<int, string>
     */
    public function availableCurrencies(string $category): array
    {
        return BankCurrencyRate::query()
            ->whereHas('bank', fn (Builder $b) => $b->where('status', 'active'))
            ->where('category', $category)
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->all();
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

    /**
     * Как formatSide(), но число выделено <b> (HTML) — для сообщений бота
     * с parse_mode='HTML'. Имя банка экранируется (untrusted для HTML-разметки).
     *
     * @param  array{value: float|null, banks: array<int, string>}  $side
     */
    public function formatSideHtml(array $side): string
    {
        if ($side['value'] === null) {
            return '—';
        }

        $banks = $side['banks'] === []
            ? ''
            : ' — '.implode(', ', array_map('htmlspecialchars', $side['banks']));

        return '<b>'.$this->formatNumber($side['value']).'</b>'.$banks;
    }

    public function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    public function categoryLabel(string $category): string
    {
        return $category === 'cash' ? 'Обмен валют' : 'Денежные переводы';
    }

    /**
     * Сводка для бота по заданным валютам (обычно MAIN_CURRENCIES) — с
     * названиями банков, HTML-разметкой (числа <b>), парой строк на валюту.
     * Отправлять с parse_mode='HTML' (TelegramService::sendMessage).
     *
     * @param  array<int, string>  $currencies
     */
    public function botRateSummary(string $category, array $currencies): string
    {
        $blocks = $this->currencyBlocksHtml($category, $currencies);

        if ($blocks === []) {
            return 'Сейчас нет данных по курсам.';
        }

        return '💱 <b>'.$this->categoryLabel($category)." — курс сегодня</b>\n\n".implode("\n\n", $blocks);
    }

    /**
     * Готовые HTML-блоки "валюта + продаёт/покупает + банки" по каждой из
     * currencies, где есть данные — общая часть botRateSummary() и постов
     * канала (RunFinancePostsScheduler), чтобы цифры собирал код, не LLM.
     *
     * @param  array<int, string>  $currencies
     * @return array<int, string>
     */
    public function currencyBlocksHtml(string $category, array $currencies): array
    {
        $blocks = [];

        foreach ($currencies as $currency) {
            $rows = $this->latestRates($category, $currency);
            $sell = $this->extreme($rows, 'sell', 'min');
            $buy = $this->extreme($rows, 'buy', 'max');

            if ($sell['value'] === null && $buy['value'] === null) {
                continue;
            }

            $blocks[] = "<b>{$currency}</b>\n"
                .'  Банк продаёт: '.$this->formatSideHtml($sell)."\n"
                .'  Банк покупает: '.$this->formatSideHtml($buy);
        }

        return $blocks;
    }

    /**
     * Компактная таблица (без банков) по валютам, не входящим в MAIN_CURRENCIES —
     * по кнопке "Другие валюты" в боте. Отправлять с parse_mode='HTML'.
     */
    public function botOtherCurrenciesTable(string $category): string
    {
        $currencies = array_values(array_diff($this->availableCurrencies($category), self::MAIN_CURRENCIES));

        if ($currencies === []) {
            return 'Других валют сейчас нет.';
        }

        $rows = [sprintf('%-6s %9s %9s', 'Валюта', 'Продаёт', 'Покупает')];

        foreach ($currencies as $currency) {
            $data = $this->latestRates($category, $currency);
            $sell = $this->extreme($data, 'sell', 'min')['value'];
            $buy = $this->extreme($data, 'buy', 'max')['value'];

            $rows[] = sprintf(
                '%-6s %9s %9s',
                $currency,
                $sell !== null ? $this->formatNumber($sell) : '—',
                $buy !== null ? $this->formatNumber($buy) : '—',
            );
        }

        return '<pre>'.implode("\n", $rows).'</pre>';
    }
}
