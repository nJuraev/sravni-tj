<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminProductResource;
use App\Models\Bank;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CRUD по продуктам (админка) + быстрый toggle статуса.
 *
 * Product guarded ['*'] — пишем через forceFill валидированных данных.
 */
class ProductController extends Controller
{
    private const FEATURE_KEYS = ['online_application', 'no_guarantor', 'capitalization', 'replenishable'];

    /**
     * Поля, которые при сохранении через админку «закрепляются» за редактором:
     * парсер не перезаписывает их (см. products.locked_fields и UpsertProduct
     * в Go-парсере). Категория/подкатегория/метки заданные вручную — приоритетны.
     */
    private const ADMIN_LOCKED_FIELDS = ['category', 'subcategory', 'features'];

    /**
     * GET /api/admin/banks/{bank}/products.
     */
    public function index(Bank $bank): JsonResponse
    {
        $products = $bank->products()
            ->orderByRaw("array_position(ARRAY['active','draft','hidden','outdated'], status)")
            ->orderBy('category')
            ->orderBy('name_ru')
            ->get();

        return AdminProductResource::collection($products)->response();
    }

    /**
     * GET /api/admin/products/{product}.
     */
    public function show(Product $product): JsonResponse
    {
        $product->load('bank');

        return response()->json(['data' => new AdminProductResource($product)]);
    }

    /**
     * POST /api/admin/products.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        // external_key обязателен в схеме; для ручного продукта генерируем,
        // если не задан (source_url_id = null, поэтому коллизий unique нет).
        if (empty($data['external_key'])) {
            $data['external_key'] = 'admin-'.Str::lower(Str::random(16));
        }

        // Ручной продукт: закрепляем категорию/подкатегорию/метки за админкой.
        $data['locked_fields'] = self::ADMIN_LOCKED_FIELDS;

        $product = new Product;
        $product->forceFill($data)->save();
        $product->load('bank');

        return response()->json(['data' => new AdminProductResource($product)], Response::HTTP_CREATED);
    }

    /**
     * PUT/PATCH /api/admin/products/{product}.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validateData($request);

        // Закрепляем отредактированные вручную поля, не теряя ранее залоченные.
        $data['locked_fields'] = array_values(array_unique(array_merge(
            $product->locked_fields ?? [],
            self::ADMIN_LOCKED_FIELDS,
        )));

        $product->forceFill($data)->save();
        $product->load('bank');

        return response()->json(['data' => new AdminProductResource($product)]);
    }

    /**
     * DELETE /api/admin/products/{product}.
     */
    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }

    /**
     * PATCH /api/admin/products/{product}/toggle.
     *
     * Быстрое вкл/откл: active ↔ hidden. Прочие статусы (draft/outdated)
     * переключаются в active.
     */
    public function toggle(Product $product): JsonResponse
    {
        $next = $product->status === 'active' ? 'hidden' : 'active';
        $product->forceFill(['status' => $next])->save();
        $product->load('bank');

        return response()->json(['data' => new AdminProductResource($product)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request): array
    {
        $validated = $request->validate([
            'bank_id' => ['required', 'integer', 'exists:banks,id'],
            'category' => ['required', Rule::in(['credit', 'deposit', 'installment'])],
            'subcategory' => ['nullable', Rule::in([
                'consumer', 'mortgage', 'auto', 'business', 'agro', 'education',
                'refinance', 'pawn', 'term', 'savings', 'demand', 'kids', 'other',
            ])],
            'is_special' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'draft', 'hidden', 'outdated'])],
            'currency' => ['required', Rule::in(['TJS', 'USD', 'EUR'])],
            'external_key' => ['nullable', 'string', 'max:255'],
            'name_ru' => ['nullable', 'string', 'max:255', 'required_without:name_tg'],
            'name_tg' => ['nullable', 'string', 'max:255', 'required_without:name_ru'],
            'description_ru' => ['nullable', 'string'],
            'description_tg' => ['nullable', 'string'],
            'rate_min' => ['required', 'numeric', 'min:0', 'max:100'],
            'rate_max' => ['required', 'numeric', 'min:0', 'max:100', 'gte:rate_min'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0', 'gte:amount_min'],
            'term_min' => ['nullable', 'integer', 'min:1'],
            'term_max' => ['nullable', 'integer', 'min:1', 'gte:term_min'],
            'features' => ['nullable', 'array'],
            // null допустим: парсер хранит неизвестные признаки как null;
            // ниже features нормализуются только по известным ключам.
            'features.*' => ['nullable', 'boolean'],
        ]);

        // Нормализуем features: только известные ключи, приводим к bool.
        $rawFeatures = $request->input('features', []);
        $features = [];
        foreach (self::FEATURE_KEYS as $key) {
            if (! empty($rawFeatures[$key])) {
                $features[$key] = true;
            }
        }
        $validated['features'] = $features;

        return $validated;
    }
}
