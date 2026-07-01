<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Bank>
 */
class BankFactory extends Factory
{
    protected $model = Bank::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name_ru' => $name,
            'name_tg' => fake()->boolean(70) ? $name.' (tg)' : null,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'contact_email' => fake()->unique()->safeEmail(),
            'website' => 'https://'.Str::slug($name).'.tj',
            'phone' => '+992 44 600 00 00',
            'address_ru' => fake()->boolean(70) ? 'г. Душанбе, '.fake()->streetAddress() : null,
            'address_tg' => null,
            'status' => 'active',
            'is_partner' => fake()->boolean(30),
            'logo_url' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'inactive']);
    }

    public function partner(): static
    {
        return $this->state(fn (array $attributes): array => ['is_partner' => true]);
    }
}
