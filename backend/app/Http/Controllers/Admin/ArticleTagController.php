<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminArticleTagResource;
use App\Models\ArticleTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * CRUD тегов статей (админка). Создаются редактором на лету из формы статьи.
 */
class ArticleTagController extends Controller
{
    /**
     * GET /api/admin/article-tags.
     */
    public function index(): JsonResponse
    {
        $tags = ArticleTag::query()->orderBy('name_ru')->get();

        return AdminArticleTagResource::collection($tags)->response();
    }

    /**
     * POST /api/admin/article-tags.
     */
    public function store(Request $request): JsonResponse
    {
        $tag = ArticleTag::create($this->validateData($request));

        return response()->json(['data' => new AdminArticleTagResource($tag)], Response::HTTP_CREATED);
    }

    /**
     * PUT/PATCH /api/admin/article-tags/{articleTag}.
     */
    public function update(Request $request, ArticleTag $articleTag): JsonResponse
    {
        $articleTag->update($this->validateData($request, $articleTag->id));

        return response()->json(['data' => new AdminArticleTagResource($articleTag)]);
    }

    /**
     * DELETE /api/admin/article-tags/{articleTag}.
     *
     * Pivot article_tag держит CASCADE — удаление тега просто снимает его со статей.
     */
    public function destroy(ArticleTag $articleTag): Response
    {
        $articleTag->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?int $tagId = null): array
    {
        return $request->validate([
            'name_ru' => ['required', 'string', 'max:60'],
            'name_tg' => ['nullable', 'string', 'max:60'],
            'slug' => [
                'required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('article_tags', 'slug')->ignore($tagId),
            ],
        ]);
    }
}
