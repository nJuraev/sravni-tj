<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\RateAlertSubscription;
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
                // Не дублировать один и тот же алерт для одного пользователя.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $exists = RateAlertSubscription::query()
                        ->where('user_id', $this->user('user')->id)
                        ->where('category', $this->input('category'))
                        ->where('currency', $value)
                        ->where('op', $this->input('op'))
                        ->where('direction', $this->input('direction'))
                        ->exists();

                    if ($exists) {
                        $fail('validation.rate_alert_duplicate')->translate();
                    }
                },
            ],
        ];
    }

    /** Не больше 3 активных алертов на пользователя. */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $count = RateAlertSubscription::query()
                ->where('user_id', $this->user('user')->id)
                ->count();

            if ($count >= 3) {
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
