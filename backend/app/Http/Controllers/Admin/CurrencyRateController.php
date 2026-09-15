<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminCurrencyRateResource;
use App\Models\BankCurrencyRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Курсы валют банков в админке: просмотр (пишет только парсер cmd/rates).
 */
class CurrencyRateController extends Controller
{
    /**
     * GET /api/admin/currency-rates.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BankCurrencyRate::query()
            ->with('bank:id,name_ru,name_tg')
            ->orderByDesc('rate_date')
            ->orderBy('currency')
            ->orderBy('category');

        if ($bankId = $request->query('bank_id')) {
            $query->where('bank_id', (int) $bankId);
        }

        if ($currency = $request->query('currency')) {
            $query->where('currency', $currency);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($rateDate = $request->query('rate_date')) {
            $query->whereDate('rate_date', $rateDate);
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 200);
        $rates = $query->paginate($perPage);

        return AdminCurrencyRateResource::collection($rates)->response();
    }
}
