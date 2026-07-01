<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RateBestRequest;
use App\Http\Requests\RateIndexRequest;
use App\Http\Resources\RateResource;
use App\Models\BankCurrencyRate;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Курсы валют банков (только чтение). Отдаются только курсы АКТИВНЫХ банков.
 *
 * Везде берётся САМЫЙ СВЕЖИЙ курс на сочетание банк×валюта×категория
 * (DISTINCT ON + ORDER BY rate_date DESC), т.к. банки публикуют посуточно.
 */
class RateController extends Controller
{
    /**
     * GET /api/rates — свежие курсы, опц. фильтр по валюте/категории.
     */
    public function index(RateIndexRequest $request): JsonResponse
    {
        $rates = $this->latestRatesQuery()
            ->when($request->filled('currency'), fn (Builder $q) => $q->where('currency', strtoupper((string) $request->string('currency'))))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', $request->string('category')))
            ->with('bank')
            ->get();

        return response()->json([
            'data' => RateResource::collection($rates),
        ]);
    }

    /**
     * GET /api/rates/best — лучший курс под операцию клиента:
     *  - op=buy  (клиент ПОКУПАЕТ валюту) → лучший = минимальный sell банка;
     *  - op=sell (клиент ПРОДАЁТ валюту)  → лучший = максимальный buy банка.
     */
    public function best(RateBestRequest $request): JsonResponse
    {
        $op = (string) $request->string('op');

        $rates = $this->latestRatesQuery()
            ->where('currency', strtoupper((string) $request->string('currency')))
            ->where('category', $request->string('category'))
            ->with('bank')
            ->get()
            // Банк должен котировать нужную сторону.
            ->filter(fn (BankCurrencyRate $r) => $op === 'buy' ? $r->sell !== null : $r->buy !== null);

        $best = $op === 'buy'
            ? $rates->sortBy(fn (BankCurrencyRate $r) => (float) $r->sell)->first()
            : $rates->sortByDesc(fn (BankCurrencyRate $r) => (float) $r->buy)->first();

        return response()->json([
            'data' => $best ? new RateResource($best) : null,
        ]);
    }

    /**
     * Базовый запрос: свежайший курс на банк×валюта×категория, только активные банки.
     *
     * @return Builder<BankCurrencyRate>
     */
    private function latestRatesQuery(): Builder
    {
        return BankCurrencyRate::query()
            ->select(DB::raw('DISTINCT ON (bank_id, currency, category) bank_currency_rates.*'))
            ->whereHas('bank', fn (Builder $b) => $b->where('status', 'active'))
            ->orderBy('bank_id')
            ->orderBy('currency')
            ->orderBy('category')
            ->orderByDesc('rate_date');
    }
}
