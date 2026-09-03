<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminArticleResource;
use App\Jobs\SendArticleToTelegramJob;
use App\Models\Article;
use App\Support\ArticleHtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CRUD статей блога (админка). Article $fillable (не forceFill) — авторский
 * контент редактора, не парсер (см. FinancePost/PostTopic).
 */
class ArticleController extends Controller
{
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
        [$data, $tagIds] = $this->validateData($request);

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
        [$data, $tagIds] = $this->validateData($request, $article->id);

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

    /**
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    private function validateData(Request $request, ?int $articleId = null): array
    {
        $validated = $request->validate([
            'title_ru' => ['required', 'string', 'max:255'],
            'title_tg' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('articles', 'slug')->ignore($articleId),
            ],
            'excerpt_ru' => ['nullable', 'string', 'max:500'],
            'excerpt_tg' => ['nullable', 'string', 'max:500'],
            'body_ru' => ['required', 'string'],
            'body_tg' => ['nullable', 'string'],
            // Пишется только сервером (uploadImage) в ответ на успешную загрузку — не свободный ввод.
            'cover_image' => ['nullable', 'url', 'max:500'],
            'youtube_url' => ['nullable', 'string', 'max:500', 'regex:#^https?://(www\.)?(youtube\.com|youtu\.be)/#i'],
            'category_id' => ['required', 'integer', 'exists:article_categories,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:article_tags,id'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'published_at' => ['nullable', 'date'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'related_bank_id' => ['nullable', 'integer', 'exists:banks,id'],
            'related_product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids']);

        // Публикация без явной даты — считаем «прямо сейчас» (chk_articles_published_has_date).
        if ($validated['status'] === 'published' && empty($validated['published_at'])) {
            $validated['published_at'] = now();
        }

        // HTML из Tiptap-редактора (или прямого POST мимо UI) — только явный whitelist
        // тегов доходит до БД; body рендерится через v-html на публичной странице.
        $validated['body_ru'] = ArticleHtmlSanitizer::clean($validated['body_ru']);
        $validated['body_tg'] = ArticleHtmlSanitizer::clean($validated['body_tg'] ?? null);

        // Анонс — задуман как обычный текст (meta description/карточка), не HTML.
        $validated['excerpt_ru'] = isset($validated['excerpt_ru']) ? strip_tags($validated['excerpt_ru']) : null;
        $validated['excerpt_tg'] = isset($validated['excerpt_tg']) ? strip_tags($validated['excerpt_tg']) : null;

        return [$validated, $tagIds];
    }
}
