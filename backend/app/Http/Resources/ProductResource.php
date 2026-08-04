<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MapsProductFields;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Полное представление продукта (контракт docs/api/contracts.md, объект Product).
 *
 * Контрактные инварианты, которые соблюдает этот ресурс:
 *  - мультиязычные поля приходят парами (*_ru / *_tg), *_tg может быть null;
 *  - rate_* агрегаты И полная сетка rate_tiers присутствуют ВСЕГДА;
 *  - features всегда содержит фиксированный набор булевых ключей контракта
 *    (отсутствующий/неизвестный признак → false);
 *  - currency продукта проставляется в каждый тир (одна валюта на продукт);
 *  - `currencies`/`variants` — аддитивное расширение контракта для группировки
 *    валютных дублей одного банковского продукта (source_url_id), см.
 *    ProductController::attachAvailableCurrencies()/loadCurrencyVariants().
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    use MapsProductFields;

    /** Порядок валют в variants/currencies: TJS первой (дефолт для тарифов банков). */
    private const CURRENCY_ORDER = ['TJS' => 0, 'USD' => 1, 'EUR' => 2];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'is_special' => (bool) $this->is_special,
            'currency' => $this->currency,
            'name_ru' => $this->name_ru,
            'name_tg' => $this->name_tg,
            'description_ru' => $this->description_ru,
            'description_tg' => $this->description_tg,
            'key_conditions_ru' => $this->key_conditions_ru,
            'key_conditions_tg' => $this->key_conditions_tg,
            'documents_ru' => $this->documents_ru,
            'documents_tg' => $this->documents_tg,
            'source_url' => $this->source_url,
            'rate_min' => (float) $this->rate_min,
            'rate_max' => (float) $this->rate_max,
            'amount_min' => $this->amount_min !== null ? (float) $this->amount_min : null,
            'amount_max' => $this->amount_max !== null ? (float) $this->amount_max : null,
            'term_min' => $this->term_min !== null ? (int) $this->term_min : null,
            'term_max' => $this->term_max !== null ? (int) $this->term_max : null,
            'rate_tiers' => $this->mapRateTiers(),
            'features' => $this->mapFeatures(),
            'bank' => new BankResource($this->whenLoaded('bank')),
            'parsed_at' => optional($this->parsed_at)->toIso8601ZuluString(),
            // Список валют группы (source_url_id) — бейджи в каталоге, без открытия
            // отдельных карточек. Присутствует только когда контроллер его проставил.
            'currencies' => $this->when($this->available_currencies !== null, fn () => $this->available_currencies),
            // Полные данные валютных вариантов группы — для табов на detail-странице.
            // Присутствует только когда контроллер явно подгрузил currencyVariants (show()).
            'variants' => $this->whenLoaded(
                'currencyVariants',
                fn () => ProductVariantResource::collection(
                    $this->currencyVariants
                        ->sortBy(fn (Product $v) => self::CURRENCY_ORDER[$v->currency] ?? 99)
                        ->values()
                ),
            ),
        ];
    }
}
