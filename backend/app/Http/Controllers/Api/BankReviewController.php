<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankReviewRequest;
use App\Http\Resources\BankReviewResource;
use App\Models\Bank;
use App\Models\BankReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Отзывы о банках.
 *
 * GET  — только ОДОБРЕННЫЕ отзывы активного банка (новые первыми).
 * POST — создаёт отзыв со status='pending' (премодерация); в рейтинг и выдачу
 *        он попадёт только после ручного approve через БД.
 */
class BankReviewController extends Controller
{
    /**
     * GET /api/banks/{bank}/reviews.
     */
    public function index(Request $request, int $bank): JsonResponse
    {
        $model = Bank::query()->active()->findOrFail($bank);

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $reviews = $model->reviews()
            ->approved()
            ->orderByDesc('created_at')
            ->paginate($perPage, page: (int) $request->integer('page', 1));

        return response()->json([
            'data' => BankReviewResource::collection($reviews->getCollection()),
            'pagination' => [
                'page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total_items' => $reviews->total(),
                'total_pages' => $reviews->total() === 0 ? 0 : $reviews->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/banks/{bank}/reviews — приём отзыва (премодерация).
     */
    public function store(StoreBankReviewRequest $request, int $bank): JsonResponse
    {
        // Отзыв можно оставить только активному, существующему банку (иначе 404).
        $model = Bank::query()->active()->findOrFail($bank);
        $data = $request->validated();

        $review = BankReview::create([
            'bank_id' => $model->id,
            'author_name' => $data['author_name'],
            'rating' => (int) $data['rating'],
            'body' => $data['body'],
            'consent' => true,
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => [
                'id' => (int) $review->id,
                'status' => $review->status,
            ],
            'message' => 'Отзыв принят и будет опубликован после модерации.',
        ], Response::HTTP_CREATED);
    }
}
