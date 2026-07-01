<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация query-параметров типовых выдач каталога
 * (GET /api/products/{credits|deposits|installments}).
 *
 * Категория задаётся эндпоинтом (не клиентом). Невалидный enum
 * (currency/subcategory/sort) или нарушение min<=max → 422 (а не молчаливый дефолт).
 */
class ProductIndexRequest extends FormRequest
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
            'currency' => ['sometimes', 'string', Rule::in(['TJS', 'USD', 'EUR'])],

            // «Галочка особые»: подмешать аномальные (is_special) продукты к обычным.
            'special' => ['sometimes', 'boolean'],

            // Мультиселект подкатегорий: продукт любой из перечисленных подкатегорий.
            'subcategory' => ['sometimes', 'array'],
            'subcategory.*' => [
                'string',
                Rule::in([
                    // credit
                    'consumer', 'mortgage', 'auto', 'business', 'agro', 'education', 'refinance', 'pawn',
                    // deposit
                    'term', 'savings', 'demand', 'kids',
                    // общий fallback
                    'other',
                ]),
            ],

            // Мультиселект банков: фильтр по одному или нескольким bank_id.
            'bank_id' => ['sometimes', 'array'],
            'bank_id.*' => ['integer', 'exists:banks,id'],

            'amount_min' => ['sometimes', 'numeric', 'gt:0'],
            'amount_max' => ['sometimes', 'numeric', 'gt:0', 'gte:amount_min'],

            'term_min' => ['sometimes', 'integer', 'min:1'],
            'term_max' => ['sometimes', 'integer', 'min:1', 'gte:term_min'],

            'rate_min' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'rate_max' => ['sometimes', 'numeric', 'gt:0', 'max:100', 'gte:rate_min'],

            'features' => ['sometimes', 'array'],
            'features.*' => [
                'string',
                Rule::in(['online_application', 'no_guarantor', 'capitalization', 'replenishment']),
            ],

            'sort' => [
                'sometimes',
                'string',
                Rule::in([
                    'rate_min', '-rate_min',
                    'rate_max', '-rate_max',
                    'amount_min', '-amount_min',
                    'term_min', '-term_min',
                    'created_at', '-created_at',
                ]),
            ],

            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
