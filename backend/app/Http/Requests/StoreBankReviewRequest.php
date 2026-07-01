<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация POST /api/banks/{bank}/reviews.
 *
 * consent (согласие на обработку ПД) обязателен и должен быть true — иначе 422
 * (как и для заявок).
 */
class StoreBankReviewRequest extends FormRequest
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
            'author_name' => ['required', 'string', 'min:2', 'max:120'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'min:10', 'max:4000'],
            'consent' => ['required', 'accepted'],
        ];
    }
}
