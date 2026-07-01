<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankCurrencyRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Публичное представление курса валюты банка.
 *
 *  - buy  — банк ПОКУПАЕТ валюту у клиента (клиент продаёт);
 *  - sell — банк ПРОДАЁТ валюту клиенту (клиент покупает);
 *  - любая сторона может быть null (банк не котирует эту операцию).
 *
 * @mixin BankCurrencyRate
 */
class RateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'bank' => new BankResource($this->whenLoaded('bank')),
            'currency' => $this->currency,
            'category' => $this->category,
            'buy' => $this->buy !== null ? (float) $this->buy : null,
            'sell' => $this->sell !== null ? (float) $this->sell : null,
            'rate_date' => optional($this->rate_date)->toDateString(),
        ];
    }
}
