<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Http\Resources\RateTierResource;
use App\Models\ProductRate;
use Illuminate\Support\Collection;

/**
 * Общая логика ProductResource / ProductVariantResource: контрактный набор
 * булевых признаков (неизвестный/отсутствующий → false) и тарифная сетка
 * с проставленной валютой продукта в каждый тир.
 */
trait MapsProductFields
{
    /**
     * Контрактный набор признаков. Ключи фиксированы контрактом (frozen).
     * В БД (ai-output-schema) признак пополнения называется "replenishable" —
     * наружу по контракту отдаётся как "replenishment".
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
