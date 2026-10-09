<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ValidatesArticleData;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminArticleResource;
use App\Jobs\SendArticleToTelegramJob;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CRUD статей блога (админка). Article $fillable (не forceFill) — авторский
 * контент редактора, не парсер (см. FinancePost/PostTopic).
 */
class ArticleController extends Controller
{
    use ValidatesArticleData;

    private const RELATIONS = ['category', 'tags', 'relatedBank', 'relatedProduct'];

    /**
     * GET /api/admin/articles.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Article::query()
            ->with(['category', 'tags'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('title_ru', 'ilike', "%{$search}%")
                    ->orWhere('title_tg', 'ilike', "%{$search}%");
            });
        }

        return AdminArticleResource::collection($query->get())->response();
    }

    /**
     * GET /api/admin/articles/{article}.
     */
    public function show(Article $article): JsonResponse
    {
        $article->load(self::RELATIONS);

        return response()->json(['data' => new AdminArticleResource($article)]);
    }

    /**
     * POST /api/admin/articles.
     */
    public function store(Request $request): JsonResponse
    {
        [$data, $tagIds] = $this->validateArticleData($request);

        $article = Article::create($data);
        $article->tags()->sync($tagIds);
        $article->load(self::RELATIONS);

        return response()->json(['data' => new AdminArticleResource($article)], Response::HTTP_CREATED);
    }

    /**
     * PUT/PATCH /api/admin/articles/{article}.
     */
    public function update(Request $request, Article $article): JsonResponse
    {
        [$data, $tagIds] = $this->validateArticleData($request, $article->id);

        $article->update($data);
        $article->tags()->sync($tagIds);
        $article->load(self::RELATIONS);

        return response()->json(['data' => new AdminArticleResource($article)]);
    }

    /**
     * DELETE /api/admin/articles/{article}.
     */
    public function destroy(Article $article): Response
    {
        $article->delete();

        return response()->noContent();
    }

    /**
     * POST /api/admin/articles/{article}/send-telegram.
     *
     * Ручной репост в TG-группу. Только для опубликованных статей — черновик
     * отправлять некуда (нет публичной ссылки на него).
     */
    public function sendTelegram(Article $article): JsonResponse
    {
        if ($article->status !== 'published') {
            return response()->json(['message' => 'Отправить в Telegram можно только опубликованную статью.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        SendArticleToTelegramJob::dispatch($article->id);
        $article->refresh()->load(self::RELATIONS);

        return response()->json(['data' => new AdminArticleResource($article)]);
    }

    /**
     * POST /api/admin/articles/upload-image.
     *
     * Картинка для обложки статьи или для вставки в тело (Tiptap-редактор).
     * Диск 'public' — сейчас storage/app/public за симлинком public/storage;
     * на Railway это должен быть persistent volume (или позже S3-совместимый
     * диск в отдельном контейнере — см. решение по хранилищу для articles),
     * иначе загрузки теряются при редеплое. Контроллер не завязан на конкретную
     * инфраструктуру — только на диск 'public' из filesystems.php.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $file = $request->file('image');
        $filename = Str::random(32).'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('articles/'.date('Y/m'), $filename, 'public');

        return response()->json([
            'data' => ['url' => Storage::disk('public')->url($path)],
        ], Response::HTTP_CREATED);
    }
}
