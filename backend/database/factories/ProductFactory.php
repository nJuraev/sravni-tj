<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bank;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rateMin = fake()->randomFloat(2, 4, 20);
        $rateMax = $rateMin + fake()->randomFloat(2, 0, 10);
        $amountMin = fake()->randomElement([1000, 5000, 10000]);
        $name = fake()->words(2, true);

        return [
            'bank_id' => Bank::factory(),
            'source_url_id' => null,
            'external_key' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'category' => fake()->randomElement(['credit', 'deposit']),
            'is_special' => false,
            'name_ru' => Str::title($name),
            'name_tg' => fake()->boolean(60) ? Str::title($name).' (tg)' : null,
            'description_ru' => fake()->boolean(70) ? fake()->sentence() : null,
            'description_tg' => null,
            'status' => 'active',
            'currency' => fake()->randomElement(['TJS', 'USD', 'EUR']),
            'rate_min' => $rateMin,
            'rate_max' => $rateMax,
            'amount_min' => $amountMin,
            'amount_max' => fake()->boolean(60) ? $amountMin * fake()->numberBetween(10, 100) : null,
            'term_min' => fake()->randomElement([3, 6, 12]),
            'term_max' => fake()->randomElement([24, 36, 60, null]),
            'features' => [
                'online_application' => fake()->boolean(),
                'no_guarantor' => fake()->boolean(),
                'capitalization' => fake()->boolean(),
                'replenishable' => fake()->boolean(),
                'early_withdrawal' => fake()->boolean(),
            ],
            'parsed_at' => now(),
        ];
    }

    public function credit(): static
    {
        return $this->state(fn (array $attributes): array => ['category' => 'credit']);
    }

    public function deposit(): static
    {
        return $this->state(fn (array $attributes): array => ['category' => 'deposit']);
    }

    public function installment(): static
    {
        return $this->state(fn (array $attributes): array => ['category' => 'installment']);
    }

    /** «Особый» (аномальный) продукт — по умолчанию скрыт из выдачи. */
    public function special(): static
    {
        return $this->state(fn (array $attributes): array => ['is_special' => true]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'draft']);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'hidden']);
    }

    /**
     * @param  array<string, bool>  $features
     */
    public function withFeatures(array $features): static
    {
        return $this->state(fn (array $attributes): array => [
            'features' => array_merge((array) ($attributes['features'] ?? []), $features),
        ]);
    }
}
