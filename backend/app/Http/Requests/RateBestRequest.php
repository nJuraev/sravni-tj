<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация query GET /api/rates/best.
 * Все параметры обязательны: курс «лучший» определён только для конкретной
 * валюты + категории + операции клиента (buy = клиент покупает валюту,
 * sell = клиент продаёт валюту банку).
 */
class RateBestRequest extends FormRequest
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
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'category' => ['required', 'string', Rule::in(['cash', 'transfer'])],
            'op' => ['required', 'string', Rule::in(['buy', 'sell'])],
        ];
    }
}
