<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MapsProductFields;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Валютный вариант продукта для табов на странице детали: в БД одна строка
 * `products` = одна валюта, несколько строк с одинаковым `source_url_id`
 * образуют группу (ProductController::loadCurrencyVariants).
 *
 * Общие поля (name/description/bank/category/subcategory) НЕ дублируются —
 * они приходят из ProductResource верхнего уровня и считаются одинаковыми
 * для всех валют группы.
 *
 * @mixin Product
 */
class ProductVariantResource extends JsonResource
{
    use MapsProductFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'currency' => $this->currency,
            'rate_min' => (float) $this->rate_min,
            'rate_max' => (float) $this->rate_max,
            'amount_min' => $this->amount_min !== null ? (float) $this->amount_min : null,
            'amount_max' => $this->amount_max !== null ? (float) $this->amount_max : null,
            'term_min' => $this->term_min !== null ? (int) $this->term_min : null,
            'term_max' => $this->term_max !== null ? (int) $this->term_max : null,
            'rate_tiers' => $this->mapRateTiers(),
            'features' => $this->mapFeatures(),
            'key_conditions_ru' => $this->key_conditions_ru,
            'key_conditions_tg' => $this->key_conditions_tg,
            'documents_ru' => $this->documents_ru,
            'documents_tg' => $this->documents_tg,
            'source_url' => $this->source_url,
            'parsed_at' => optional($this->parsed_at)->toIso8601ZuluString(),
        ];
    }
}
