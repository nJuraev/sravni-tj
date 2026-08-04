<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature-тесты профиля telegram-пользователя (GET/PATCH /api/profile).
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_without_token_returns_401(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    public function test_show_returns_current_user(): void
    {
        $user = User::factory()->telegram()->create(['name' => 'Иван', 'phone' => null]);

        $this->withHeader('Authorization', "Bearer {$user->api_token}")
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Иван')
            ->assertJsonPath('data.phone', null);
    }

    public function test_update_sets_name_and_normalizes_phone(): void
    {
        $user = User::factory()->telegram()->create();

        $this->withHeader('Authorization', "Bearer {$user->api_token}")
            ->patchJson('/api/profile', [
                'name' => 'Мадина',
                'phone' => '+992 90 123 45 67',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Мадина')
            ->assertJsonPath('data.phone', '+992901234567');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Мадина', 'phone' => '+992901234567']);
    }

    public function test_update_invalid_name_returns_422(): void
    {
        $user = User::factory()->telegram()->create();

        $this->withHeader('Authorization', "Bearer {$user->api_token}")
            ->patchJson('/api/profile', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_wrong_token_returns_401(): void
    {
        User::factory()->telegram()->create();

        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/profile')
            ->assertStatus(401);
    }
}
