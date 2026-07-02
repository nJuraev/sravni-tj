<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Публичное представление банка (контракт docs/api/contracts.md, объект Bank / BankRef).
 *
 * Поле status НЕ отдаётся наружу (служебное). contact_email — публичный
 * справочный контакт из реестра НБТ (НЕ адрес доставки заявок, тот живёт
 * на bank_source_urls.email и наружу не отдаётся).
 *
 * @mixin Bank
 */
class BankResource extends JsonResource
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
            'is_partner' => (bool) $this->is_partner,
            'website' => $this->website,
            'phone' => $this->phone,
            'address_ru' => $this->address_ru,
            'address_tg' => $this->address_tg,
            'about_ru' => $this->about_ru,
            'about_tg' => $this->about_tg,
            'contact_email' => $this->contact_email,
            // Агрегаты рейтинга (по одобренным отзывам). Присутствуют, если
            // запрос загрузил их через scopeWithReviewStats; иначе null/0.
            'rating_avg' => $this->rating_avg !== null ? round((float) $this->rating_avg, 1) : null,
            'rating_count' => (int) ($this->reviews_count ?? 0),
        ];
    }
}
