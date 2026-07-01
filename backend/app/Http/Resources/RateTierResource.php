<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ячейка тарифной сетки (контракт docs/api/contracts.md, объект RateTier).
 *
 * currency наследуется от продукта (одна валюта на продукт) и проставляется
 * родительским ProductResource через additional-данные, т.к. в product_rates
 * валюта не хранится.
 *
 * @mixin ProductRate
 */
class RateTierResource extends JsonResource
{
    public function __construct(ProductRate $resource, private readonly string $currency)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->currency,
            'amount_from' => $this->amount_min !== null ? (float) $this->amount_min : null,
            'amount_to' => $this->amount_max !== null ? (float) $this->amount_max : null,
            'term_from' => $this->term_min !== null ? (int) $this->term_min : null,
            'term_to' => $this->term_max !== null ? (int) $this->term_max : null,
            'rate' => (float) $this->rate,
        ];
    }
}
