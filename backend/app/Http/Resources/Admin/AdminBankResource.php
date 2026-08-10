<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Представление банка для админки — со служебными полями (status, slug),
 * которые публичный BankResource скрывает.
 *
 * @mixin Bank
 */
class AdminBankResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name_ru' => $this->name_ru,
            'name_tg' => $this->name_tg,
            'slug' => $this->slug,
            'status' => $this->status,
            'is_partner' => (bool) $this->is_partner,
            'contact_email' => $this->contact_email,
            'website' => $this->website,
            'phone' => $this->phone,
            'address_ru' => $this->address_ru,
            'address_tg' => $this->address_tg,
            'about_ru' => $this->about_ru,
            'about_tg' => $this->about_tg,
            'logo_url' => $this->logo_url,
            // Счётчики (присутствуют, если загружены через withCount).
            'products_count' => $this->whenCounted('products'),
            'leads_count' => $this->whenCounted('leads'),
            // Когда парсер последний раз УСПЕШНО (>0 записанных строк) обновил
            // продукты/курсы этого банка — null, если ни разу. См. banks.products_updated_at/
            // rates_updated_at (проставляются Go-парсером, не при каждом запуске).
            'products_updated_at' => optional($this->products_updated_at)->toIso8601ZuluString(),
            'rates_updated_at' => optional($this->rates_updated_at)->toIso8601ZuluString(),
            'created_at' => optional($this->created_at)->toIso8601ZuluString(),
            'updated_at' => optional($this->updated_at)->toIso8601ZuluString(),
        ];
    }
}
