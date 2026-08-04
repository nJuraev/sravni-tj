<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RateAlertSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateAlertSubscription>
 */
class RateAlertSubscriptionFactory extends Factory
{
    protected $model = RateAlertSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => fake()->randomElement(['cash', 'transfer']),
            'currency' => fake()->randomElement(['USD', 'EUR']),
            'op' => fake()->randomElement(['buy', 'sell']),
            'direction' => fake()->randomElement(['above', 'below']),
            'threshold' => fake()->randomFloat(4, 9, 12),
        ];
    }
}
