<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ValidatesArticleData;
use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/articles/publish — публикация статьи блога в обход админки
 * (внешняя автоматизация: n8n/Zapier/скрипт). Те же поля и та же санитизация
 * HTML, что у Admin\ArticleController (см. ValidatesArticleData) — только
 * авторизация другая: статичный Bearer-токен (middleware
 * article.publish.token) + rate limit 3/час (throttle:article-publish),
 * а не admin_users guard.
 */
class ArticlePublishController extends Controller
{
    use ValidatesArticleData;

    public function store(Request $request): JsonResponse
    {
        [$data, $tagIds] = $this->validateArticleData($request);

        $article = Article::create($data);
        $article->tags()->sync($tagIds);

        $frontendUrl = rtrim((string) config('services.telegram.frontend_url'), '/');

        return response()->json([
            'data' => [
                'id' => $article->id,
                'slug' => $article->slug,
                'status' => $article->status,
                'url' => "{$frontendUrl}/blog/{$article->slug}",
            ],
        ], Response::HTTP_CREATED);
    }
}
