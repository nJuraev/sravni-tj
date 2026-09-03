<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\ArticleCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticleCategory
 */
class AdminArticleCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name_ru' => $this->name_ru,
            'name_tg' => $this->name_tg,
            'slug' => $this->slug,
            'is_active' => (bool) $this->is_active,
            'articles_count' => $this->when($this->articles_count !== null, fn () => (int) $this->articles_count),
        ];
    }
}
