<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация PATCH /api/profile. Телефон — необязательный, как и в остальном
 * MVP (только telegram гарантирует идентичность пользователя).
 */
class UpdateProfileRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'min:5', 'max:32'],
        ];
    }

    /**
     * Нормализация телефона — как в StoreLeadRequest: только цифры + ведущий "+".
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone) && $phone !== '') {
            $hasPlus = str_starts_with(ltrim($phone), '+');
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            $this->merge([
                'phone' => $hasPlus ? '+'.$digits : $digits,
            ]);
        }
    }
}
