<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Product;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация тела POST /api/leads (контракт docs/api/contracts.md).
 *
 * Инварианты:
 *  - consent ОБЯЗАТЕЛЕН и строго true (правило accepted) → иначе 422;
 *  - product_id должен ссылаться на ВИДИМЫЙ продукт (active + банк active);
 *  - bank_id клиент НЕ присылает — он определяется сервером в контроллере.
 */
class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'string', 'min:5', 'max:32'],
            'product_id' => [
                'required',
                'integer',
                // exists среди ВИДИМЫХ продуктов (active + банк active).
                function (string $attribute, mixed $value, Closure $fail): void {
                    $visible = Product::query()
                        ->visible()
                        ->whereKey($value)
                        ->exists();

                    if (! $visible) {
                        $fail('validation.lead_product_unavailable')->translate();
                    }
                },
            ],
            // accepted = true | "true" | 1 | "1" | "on" | "yes"; любое != true → 422.
            'consent' => ['required', 'accepted'],
        ];
    }

    /**
     * Нормализация телефона на сервере до валидации: убираем всё, кроме цифр
     * и ведущего "+". Это и приводит хранимое значение к каноничному виду
     * (контракт: "+992 90 123 45 67" → "+992901234567").
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone)) {
            $hasPlus = str_starts_with(ltrim($phone), '+');
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            $this->merge([
                'phone' => $hasPlus ? '+'.$digits : $digits,
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent.required' => __('validation.lead_consent'),
            'consent.accepted' => __('validation.lead_consent'),
        ];
    }
}
