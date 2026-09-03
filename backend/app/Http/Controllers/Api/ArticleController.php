<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ArticleIndexRequest;
use App\Http\Resources\ArticleCategoryResource;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Http\JsonResponse;

/**
 * Блог (только чтение). Выдача всегда через Article::visible()
 * (status=published И published_at<=now), см. backend.md §3.1 для аналогии
 * с Product::scopeVisible().
 */
class ArticleController extends Controller
{
    /**
     * GET /api/articles — список, фильтр по категории/тегу (slug), пагинация.
     */
    public function index(ArticleIndexRequest $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $query = Article::query()
            ->visible()
            ->with(['category', 'tags'])
            ->orderByDesc('published_at');

        if ($category = $request->string('category')->toString()) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $category));
        }

        if ($tag = $request->string('tag')->toString()) {
            $query->whereHas('tags', fn ($q) => $q->where('slug', $tag));
        }

        $paginator = $query->paginate(
            perPage: $perPage,
            page: (int) $request->integer('page', 1),
        );

        return response()->json([
            'data' => ArticleResource::collection($paginator->getCollection()),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total_items' => $paginator->total(),
                'total_pages' => $paginator->total() === 0 ? 0 : $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/articles/{slug} — карточка статьи. 404 для черновика/будущей публикации/несуществующей.
     */
    public function show(string $slug): JsonResponse
    {
        $article = Article::query()
            ->visible()
            ->with(['category', 'tags', 'relatedBank', 'relatedProduct'])
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json(['data' => new ArticleResource($article)]);
    }

    /**
     * GET /api/article-categories — категории для фильтра на /blog.
     */
    public function categories(): JsonResponse
    {
        $categories = ArticleCategory::query()
            ->active()
            ->orderBy('name_ru')
            ->get();

        return ArticleCategoryResource::collection($categories)->response();
    }
}
