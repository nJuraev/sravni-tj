<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinancePost;
use App\Models\Product;

/**
 * Выбор продукта для «дня продукта» (см. PostTopicSelector::WEEKLY_PATTERN).
 * Исключает продукты, уже упоминавшиеся в постах за последние 30 дней,
 * чтобы не повторять один и тот же продукт слишком часто.
 */
class ProductSpotlightSelector
{
    private const AVOID_REPEAT_DAYS = 30;

    public function selectNext(): ?Product
    {
        $recentlyUsedIds = FinancePost::query()
            ->where('subject_type', 'product')
            ->where('created_at', '>=', now()->subDays(self::AVOID_REPEAT_DAYS))
            ->pluck('subject_id')
            ->filter()
            ->all();

        $product = Product::query()
            ->visible()
            ->when($recentlyUsedIds !== [], fn ($query) => $query->whereNotIn('id', $recentlyUsedIds))
            ->inRandomOrder()
            ->first();

        // Каталог маленький и все активные продукты уже засветились за 30 дней —
        // лучше повторить продукт, чем пропустить публикацию.
        return $product ?? Product::query()->visible()->inRandomOrder()->first();
    }
}
