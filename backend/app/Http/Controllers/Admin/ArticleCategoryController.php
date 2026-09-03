<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminArticleCategoryResource;
use App\Models\ArticleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * CRUD категорий статей (админка). Список расширяется редактором без деплоя —
 * см. решение в схеме articles (не CHECK-enum, как products.category).
 */
class ArticleCategoryController extends Controller
{
    /**
     * GET /api/admin/article-categories.
     */
    public function index(): JsonResponse
    {
        $categories = ArticleCategory::query()
            ->withCount('articles')
            ->orderBy('name_ru')
            ->get();

        return AdminArticleCategoryResource::collection($categories)->response();
    }

    /**
     * POST /api/admin/article-categories.
     */
    public function store(Request $request): JsonResponse
    {
        $category = ArticleCategory::create($this->validateData($request));

        return response()->json(['data' => new AdminArticleCategoryResource($category)], Response::HTTP_CREATED);
    }

    /**
     * PUT/PATCH /api/admin/article-categories/{articleCategory}.
     */
    public function update(Request $request, ArticleCategory $articleCategory): JsonResponse
    {
        $articleCategory->update($this->validateData($request, $articleCategory->id));

        return response()->json(['data' => new AdminArticleCategoryResource($articleCategory)]);
    }

    /**
     * DELETE /api/admin/article-categories/{articleCategory}.
     *
     * FK articles.category_id держит RESTRICT — категорию со статьями удалить
     * нельзя (сначала переназначь статьи на другую категорию).
     */
    public function destroy(ArticleCategory $articleCategory): Response
    {
        $articleCategory->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?int $categoryId = null): array
    {
        return $request->validate([
            'name_ru' => ['required', 'string', 'max:100'],
            'name_tg' => ['nullable', 'string', 'max:100'],
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('article_categories', 'slug')->ignore($categoryId),
            ],
            'is_active' => ['boolean'],
        ]);
    }
}
