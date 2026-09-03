<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Публичное представление статьи блога. Отдаётся только через
 * Article::visible() (status=published И published_at<=now) — см.
 * Api\ArticleController.
 *
 * @mixin Article
 */
class ArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title_ru' => $this->title_ru,
            'title_tg' => $this->title_tg,
            'slug' => $this->slug,
            'excerpt_ru' => $this->excerpt_ru,
            'excerpt_tg' => $this->excerpt_tg,
            'body_ru' => $this->body_ru,
            'body_tg' => $this->body_tg,
            'cover_image' => $this->cover_image,
            'youtube_url' => $this->youtube_url,
            'category' => new ArticleCategoryResource($this->whenLoaded('category')),
            'tags' => ArticleTagResource::collection($this->whenLoaded('tags')),
            'published_at' => optional($this->published_at)->toIso8601ZuluString(),
            'author_name' => $this->author_name,
            'related_bank' => $this->whenLoaded('relatedBank', fn () => $this->relatedBank !== null ? [
                'id' => (int) $this->relatedBank->id,
                'name_ru' => $this->relatedBank->name_ru,
                'name_tg' => $this->relatedBank->name_tg,
            ] : null),
            'related_product' => $this->whenLoaded('relatedProduct', fn () => $this->relatedProduct !== null ? [
                'id' => (int) $this->relatedProduct->id,
                'name_ru' => $this->relatedProduct->name_ru,
                'name_tg' => $this->relatedProduct->name_tg,
                'category' => $this->relatedProduct->category,
            ] : null),
        ];
    }
}
