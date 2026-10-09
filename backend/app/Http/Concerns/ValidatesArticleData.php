<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Support\ArticleHtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Общая валидация полей статьи блога — переиспользуется админкой
 * (Admin\ArticleController) и внешней публикацией (Api\ArticlePublishController),
 * чтобы санитизация HTML/дефолты не разъезжались между двумя входами записи.
 */
trait ValidatesArticleData
{
    /**
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    protected function validateArticleData(Request $request, ?int $articleId = null): array
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

        // HTML (из Tiptap-редактора или прямого POST мимо UI/внешнего паблиша) —
        // только явный whitelist тегов доходит до БД; body рендерится через
        // v-html на публичной странице.
        $validated['body_ru'] = ArticleHtmlSanitizer::clean($validated['body_ru']);
        $validated['body_tg'] = ArticleHtmlSanitizer::clean($validated['body_tg'] ?? null);

        // Анонс — задуман как обычный текст (meta description/карточка), не HTML.
        $validated['excerpt_ru'] = isset($validated['excerpt_ru']) ? strip_tags($validated['excerpt_ru']) : null;
        $validated['excerpt_tg'] = isset($validated['excerpt_tg']) ? strip_tags($validated['excerpt_tg']) : null;

        return [$validated, $tagIds];
    }
}
