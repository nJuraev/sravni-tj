<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Представление продукта для админки — все поля редактирования, включая
 * status/external_key/features. Денежные decimal-строки приводятся к числам.
 *
 * @mixin Product
 */
class AdminProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'bank_id' => (int) $this->bank_id,
            'source_url_id' => $this->source_url_id !== null ? (int) $this->source_url_id : null,
            'external_key' => $this->external_key,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'is_special' => (bool) $this->is_special,
            'status' => $this->status,
            'currency' => $this->currency,
            'name_ru' => $this->name_ru,
            'name_tg' => $this->name_tg,
            'description_ru' => $this->description_ru,
            'description_tg' => $this->description_tg,
            'rate_min' => $this->rate_min !== null ? (float) $this->rate_min : null,
            'rate_max' => $this->rate_max !== null ? (float) $this->rate_max : null,
            'amount_min' => $this->amount_min !== null ? (float) $this->amount_min : null,
            'amount_max' => $this->amount_max !== null ? (float) $this->amount_max : null,
            'term_min' => $this->term_min !== null ? (int) $this->term_min : null,
            'term_max' => $this->term_max !== null ? (int) $this->term_max : null,
            'features' => (object) ($this->features ?? []),
            'locked_fields' => $this->locked_fields ?? [],
            'bank' => new AdminBankResource($this->whenLoaded('bank')),
            'parsed_at' => optional($this->parsed_at)->toIso8601ZuluString(),
            'created_at' => optional($this->created_at)->toIso8601ZuluString(),
            'updated_at' => optional($this->updated_at)->toIso8601ZuluString(),
        ];
    }
}
