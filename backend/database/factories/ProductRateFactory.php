<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRate>
 */
class ProductRateFactory extends Factory
{
    protected $model = ProductRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'term_min' => 3,
            'term_max' => 12,
            'amount_min' => 5000,
            'amount_max' => 50000,
            'rate' => fake()->randomFloat(2, 4, 20),
        ];
    }

    /**
     * Тир с явными границами для точных тестов тарифной сетки.
     */
    public function tier(?int $termMin, ?int $termMax, ?float $amountMin, ?float $amountMax, float $rate): static
    {
        return $this->state(fn (array $attributes): array => [
            'term_min' => $termMin,
            'term_max' => $termMax,
            'amount_min' => $amountMin,
            'amount_max' => $amountMax,
            'rate' => $rate,
        ]);
    }
}
