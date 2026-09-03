<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ArticleTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticleTag
 */
class ArticleTagResource extends JsonResource
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
        ];
    }
}
