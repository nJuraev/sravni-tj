<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminLeadResource;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Заявки в админке: просмотр (список + детали) и удаление.
 */
class LeadController extends Controller
{
    /**
     * GET /api/admin/leads.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Lead::query()
            ->with(['product:id,name_ru,name_tg,category,currency', 'bank:id,name_ru,name_tg'])
            ->orderByDesc('created_at');

        if ($bankId = $request->query('bank_id')) {
            $query->where('bank_id', (int) $bankId);
        }

        if ($productId = $request->query('product_id')) {
            $query->where('product_id', (int) $productId);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('full_name', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $leads = $query->paginate($perPage);

        return AdminLeadResource::collection($leads)->response();
    }

    /**
     * GET /api/admin/leads/{lead}.
     */
    public function show(Lead $lead): JsonResponse
    {
        $lead->load(['product', 'bank']);

        return response()->json(['data' => new AdminLeadResource($lead)]);
    }

    /**
     * DELETE /api/admin/leads/{lead}.
     */
    public function destroy(Lead $lead): Response
    {
        $lead->delete();

        return response()->noContent();
    }
}
