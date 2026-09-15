<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\BankCurrencyRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankCurrencyRate
 */
class AdminCurrencyRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'bank_id' => (int) $this->bank_id,
            'bank' => $this->whenLoaded('bank', fn () => $this->bank ? [
                'id' => (int) $this->bank->id,
                'name_ru' => $this->bank->name_ru,
                'name_tg' => $this->bank->name_tg,
            ] : null),
            'currency' => $this->currency,
            'category' => $this->category,
            'buy' => $this->buy !== null ? (float) $this->buy : null,
            'sell' => $this->sell !== null ? (float) $this->sell : null,
            'rate_date' => optional($this->rate_date)->toDateString(),
            'parsed_at' => optional($this->parsed_at)->toIso8601ZuluString(),
        ];
    }
}
