<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Представление заявки для админки. Включает краткие данные продукта и банка,
 * к которым относится заявка.
 *
 * @mixin Lead
 */
class AdminLeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'consent' => (bool) $this->consent,
            'product_id' => $this->product_id !== null ? (int) $this->product_id : null,
            'bank_id' => (int) $this->bank_id,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => (int) $this->product->id,
                'name_ru' => $this->product->name_ru,
                'name_tg' => $this->product->name_tg,
                'category' => $this->product->category,
                'currency' => $this->product->currency,
            ] : null),
            'bank' => $this->whenLoaded('bank', fn () => $this->bank ? [
                'id' => (int) $this->bank->id,
                'name_ru' => $this->bank->name_ru,
                'name_tg' => $this->bank->name_tg,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601ZuluString(),
        ];
    }
}
