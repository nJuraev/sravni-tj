<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация query GET /api/rates.
 * Валюта — открытый набор (3-буквенный ISO-код); категория — enum cash|transfer.
 */
class RateIndexRequest extends FormRequest
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
            'currency' => ['sometimes', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'category' => ['sometimes', 'string', Rule::in(['cash', 'transfer'])],
        ];
    }
}
