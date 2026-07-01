<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bank;
use App\Models\BankReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankReview>
 */
class BankReviewFactory extends Factory
{
    protected $model = BankReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_id' => Bank::factory(),
            'author_name' => fake()->name(),
            'rating' => fake()->numberBetween(1, 5),
            'body' => fake()->sentence(12),
            'consent' => true,
            'status' => 'approved',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'pending']);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'rejected']);
    }
}
