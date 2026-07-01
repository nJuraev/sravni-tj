<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Полное представление продукта (контракт docs/api/contracts.md, объект Product).
 *
 * Контрактные инварианты, которые соблюдает этот ресурс:
 *  - мультиязычные поля приходят парами (*_ru / *_tg), *_tg может быть null;
 *  - rate_* агрегаты И полная сетка rate_tiers присутствуют ВСЕГДА;
 *  - features всегда содержит фиксированный набор булевых ключей контракта
 *    (отсутствующий/неизвестный признак → false);
 *  - currency продукта проставляется в каждый тир (одна валюта на продукт).
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * Контрактный набор булевых признаков. Ключи фиксированы контрактом
     * (frozen). В БД (ai-output-schema) признак пополнения называется
     * "replenishable" — наружу по контракту отдаётся как "replenishment".
     *
     * @var array<string, string> map[contractKey => storageKey]
     */
    private const FEATURE_MAP = [
        'online_application' => 'online_application',
        'no_guarantor' => 'no_guarantor',
        'capitalization' => 'capitalization',
        'replenishment' => 'replenishable',
    ];

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
        ];
    }

    /**
     * Тарифная сетка с проставленной валютой продукта в каждый тир.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapRateTiers(): array
    {
        /** @var Collection<int, ProductRate> $rates */
        $rates = $this->whenLoaded('rates', fn () => $this->rates, collect());

        if (! $rates instanceof Collection) {
            $rates = collect($rates);
        }

        return $rates
            ->map(fn (ProductRate $rate): array => (new RateTierResource($rate, $this->currency))->toArray(request()))
            ->values()
            ->all();
    }

    /**
     * Фиксированный набор булевых признаков контракта; неизвестное → false.
     *
     * @return array<string, bool>
     */
    private function mapFeatures(): array
    {
        $stored = is_array($this->features) ? $this->features : [];

        $out = [];
        foreach (self::FEATURE_MAP as $contractKey => $storageKey) {
            $out[$contractKey] = ($stored[$storageKey] ?? false) === true;
        }

        return $out;
    }
}
