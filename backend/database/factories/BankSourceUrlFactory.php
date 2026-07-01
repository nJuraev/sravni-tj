<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bank;
use App\Models\BankSourceUrl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankSourceUrl>
 */
class BankSourceUrlFactory extends Factory
{
    protected $model = BankSourceUrl::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = fake()->randomElement(['credit', 'deposit']);

        return [
            'bank_id' => Bank::factory(),
            'category' => $category,
            // url уникален (uq_bsu_url) — генерируем разные домены.
            'url' => 'https://'.fake()->unique()->domainWord().'.tj/'.$category,
            // Адрес доставки лида для этой категории.
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
            'last_parsed_at' => null,
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
}
