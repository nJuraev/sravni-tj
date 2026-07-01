<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminBankResource;
use App\Models\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * CRUD по банкам (админка).
 *
 * Bank guarded ['*'] — пишем через forceFill валидированных данных.
 */
class BankController extends Controller
{
    /**
     * GET /api/admin/banks.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Bank::query()
            ->withCount(['products', 'leads'])
            ->orderBy('name_ru');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name_ru', 'ilike', "%{$search}%")
                    ->orWhere('name_tg', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return AdminBankResource::collection($query->get())->response();
    }

    /**
     * GET /api/admin/banks/{bank}.
     */
    public function show(Bank $bank): JsonResponse
    {
        $bank->loadCount(['products', 'leads']);

        return response()->json(['data' => new AdminBankResource($bank)]);
    }

    /**
     * POST /api/admin/banks.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $bank = new Bank;
        $bank->forceFill($data)->save();
        $bank->loadCount(['products', 'leads']);

        return response()->json(['data' => new AdminBankResource($bank)], Response::HTTP_CREATED);
    }

    /**
     * PUT/PATCH /api/admin/banks/{bank}.
     */
    public function update(Request $request, Bank $bank): JsonResponse
    {
        $data = $this->validateData($request, $bank->id);

        $bank->forceFill($data)->save();
        $bank->loadCount(['products', 'leads']);

        return response()->json(['data' => new AdminBankResource($bank)]);
    }

    /**
     * DELETE /api/admin/banks/{bank}.
     *
     * Каскад удалит продукты банка (FK cascade). Заявки (leads) держат
     * RESTRICT на bank_id — банк с заявками удалить нельзя.
     */
    public function destroy(Bank $bank): Response
    {
        $bank->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?int $bankId = null): array
    {
        return $request->validate([
            'name_ru' => ['required', 'string', 'max:255'],
            'name_tg' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('banks', 'slug')->ignore($bankId),
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'is_partner' => ['boolean'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:150'],
            'address_ru' => ['nullable', 'string', 'max:500'],
            'address_tg' => ['nullable', 'string', 'max:500'],
            'logo_url' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
