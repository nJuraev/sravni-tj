<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\RateAlertSubscriptionService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRateAlertRequest extends FormRequest
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
            'category' => ['required', Rule::in(['cash', 'transfer'])],
            'op' => ['required', Rule::in(['buy', 'sell'])],
            'direction' => ['required', Rule::in(['above', 'below'])],
            'threshold' => ['required', 'numeric', 'gt:0'],
            'currency' => [
                'required',
                'string',
                'regex:/^[A-Za-z]{3}$/',
                // Не дублировать один и тот же алерт для одного пользователя
                // (правило общее с ботом — см. RateAlertSubscriptionService).
                function (string $attribute, mixed $value, Closure $fail): void {
                    $isDuplicate = app(RateAlertSubscriptionService::class)->isDuplicate(
                        $this->user('user'),
                        (string) $this->input('category'),
                        $value,
                        (string) $this->input('op'),
                        (string) $this->input('direction'),
                    );

                    if ($isDuplicate) {
                        $fail('validation.rate_alert_duplicate')->translate();
                    }
                },
            ],
        ];
    }

    /** Не больше 3 активных алертов на пользователя (правило общее с ботом). */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            if (app(RateAlertSubscriptionService::class)->hasReachedLimit($this->user('user'))) {
                $validator->errors()->add('threshold', __('validation.rate_alert_limit'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $currency = $this->input('currency');

        if (is_string($currency)) {
            $this->merge(['currency' => strtoupper($currency)]);
        }
    }
}
