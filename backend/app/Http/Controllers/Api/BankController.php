<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BankResource;
use App\Models\Bank;
use Illuminate\Http\JsonResponse;

/**
 * Список банков (только чтение). Контракт: список не пагинируется (до ~20 банков).
 */
class BankController extends Controller
{
    /**
     * GET /api/banks — только активные банки (status=active).
     */
    public function index(): JsonResponse
    {
        $banks = Bank::query()
            ->active()
            ->withReviewStats()
            ->orderBy('name_ru')
            ->get();

        return response()->json([
            'data' => BankResource::collection($banks),
        ]);
    }
}
