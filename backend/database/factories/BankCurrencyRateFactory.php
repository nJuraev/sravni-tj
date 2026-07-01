<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bank;
use App\Models\BankCurrencyRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankCurrencyRate>
 */
class BankCurrencyRateFactory extends Factory
{
    protected $model = BankCurrencyRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $buy = fake()->randomFloat(4, 9, 12);

        return [
            'bank_id' => Bank::factory(),
            'currency' => fake()->randomElement(['USD', 'EUR', 'RUB']),
            'category' => fake()->randomElement(['cash', 'transfer']),
            'buy' => $buy,
            'sell' => $buy + fake()->randomFloat(4, 0.05, 0.5),
            'rate_date' => now()->toDateString(),
            'parsed_at' => now(),
        ];
    }

    public function cash(): static
    {
        return $this->state(fn (): array => ['category' => 'cash']);
    }

    public function transfer(): static
    {
        return $this->state(fn (): array => ['category' => 'transfer']);
    }
}
