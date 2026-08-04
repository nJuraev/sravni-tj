<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RateAlertSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature-тесты алертов по курсу (GET/POST/DELETE /api/profile/alerts).
 */
class RateAlertTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => "Bearer {$user->api_token}"];
    }

    public function test_store_creates_alert(): void
    {
        $user = User::factory()->telegram()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/profile/alerts', [
                'category' => 'cash',
                'currency' => 'usd',
                'op' => 'buy',
                'direction' => 'above',
                'threshold' => 11.25,
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.direction', 'above')
            ->assertJsonPath('data.threshold', 11.25);

        $this->assertDatabaseHas('rate_alert_subscriptions', [
            'user_id' => $user->id,
            'currency' => 'USD',
            'category' => 'cash',
            'op' => 'buy',
            'direction' => 'above',
        ]);
    }

    public function test_store_rejects_fourth_alert(): void
    {
        $user = User::factory()->telegram()->create();
        foreach ([
            ['currency' => 'USD', 'op' => 'buy'],
            ['currency' => 'EUR', 'op' => 'buy'],
            ['currency' => 'USD', 'op' => 'sell'],
        ] as $attrs) {
            RateAlertSubscription::factory()->for($user)->create([
                'category' => 'cash', 'direction' => 'above', ...$attrs,
            ]);
        }

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/profile/alerts', [
                'category' => 'transfer',
                'currency' => 'EUR',
                'op' => 'sell',
                'direction' => 'below',
                'threshold' => 12,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['threshold']);

        $this->assertDatabaseCount('rate_alert_subscriptions', 3);
    }

    public function test_same_pair_different_direction_is_allowed(): void
    {
        $user = User::factory()->telegram()->create();
        RateAlertSubscription::factory()->for($user)->create([
            'category' => 'cash', 'currency' => 'USD', 'op' => 'buy', 'direction' => 'above', 'threshold' => 11,
        ]);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/profile/alerts', [
                'category' => 'cash', 'currency' => 'USD', 'op' => 'buy', 'direction' => 'below', 'threshold' => 10,
            ])
            ->assertCreated();
    }

    public function test_duplicate_alert_returns_422(): void
    {
        $user = User::factory()->telegram()->create();
        RateAlertSubscription::factory()->for($user)->create([
            'category' => 'cash', 'currency' => 'USD', 'op' => 'buy', 'direction' => 'above', 'threshold' => 10,
        ]);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/profile/alerts', [
                'category' => 'cash', 'currency' => 'USD', 'op' => 'buy', 'direction' => 'above', 'threshold' => 12,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_index_lists_only_own_alerts(): void
    {
        $user = User::factory()->telegram()->create();
        $other = User::factory()->telegram()->create();
        RateAlertSubscription::factory()->for($user)->create();
        RateAlertSubscription::factory()->for($other)->create();

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/profile/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_destroy_removes_own_alert(): void
    {
        $user = User::factory()->telegram()->create();
        $alert = RateAlertSubscription::factory()->for($user)->create();

        $this->withHeaders($this->authHeader($user))
            ->deleteJson("/api/profile/alerts/{$alert->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('rate_alert_subscriptions', ['id' => $alert->id]);
    }

    public function test_destroy_other_users_alert_returns_404(): void
    {
        $user = User::factory()->telegram()->create();
        $other = User::factory()->telegram()->create();
        $alert = RateAlertSubscription::factory()->for($other)->create();

        $this->withHeaders($this->authHeader($user))
            ->deleteJson("/api/profile/alerts/{$alert->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('rate_alert_subscriptions', ['id' => $alert->id]);
    }
}
