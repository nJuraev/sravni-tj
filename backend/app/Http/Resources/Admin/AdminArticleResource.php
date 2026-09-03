<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Article
 */
class AdminArticleResource extends JsonResource
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
            'category_id' => (int) $this->category_id,
            'category' => new AdminArticleCategoryResource($this->whenLoaded('category')),
            'tag_ids' => $this->whenLoaded('tags', fn () => $this->tags->pluck('id')->map(fn ($id) => (int) $id)->all()),
            'tags' => AdminArticleTagResource::collection($this->whenLoaded('tags')),
            'status' => $this->status,
            'published_at' => optional($this->published_at)->toIso8601ZuluString(),
            'telegram_sent_at' => optional($this->telegram_sent_at)->toIso8601ZuluString(),
            'author_name' => $this->author_name,
            'related_bank_id' => $this->related_bank_id !== null ? (int) $this->related_bank_id : null,
            'related_product_id' => $this->related_product_id !== null ? (int) $this->related_product_id : null,
            'created_at' => optional($this->created_at)->toIso8601ZuluString(),
            'updated_at' => optional($this->updated_at)->toIso8601ZuluString(),
        ];
    }
}
