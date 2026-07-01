<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Публичное представление отзыва о банке (только одобренные отдаются наружу).
 *
 * @mixin BankReview
 */
class BankReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'author_name' => $this->author_name,
            'rating' => (int) $this->rating,
            'body' => $this->body,
            'created_at' => optional($this->created_at)->toIso8601ZuluString(),
        ];
    }
}
