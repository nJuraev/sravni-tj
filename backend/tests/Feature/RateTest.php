<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\BankCurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature-тесты курсов валют (GET /api/rates, /api/rates/best).
 */
class RateTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_latest_rate_per_group(): void
    {
        $bank = Bank::factory()->create();

        // Старый и свежий курс на ту же группу — должен вернуться только свежий.
        BankCurrencyRate::factory()->for($bank, 'bank')->create([
            'currency' => 'USD', 'category' => 'cash', 'buy' => 10.0, 'sell' => 10.5,
            'rate_date' => '2026-06-10',
        ]);
        BankCurrencyRate::factory()->for($bank, 'bank')->create([
            'currency' => 'USD', 'category' => 'cash', 'buy' => 11.0, 'sell' => 11.5,
            'rate_date' => '2026-06-14',
        ]);

        $this->getJson('/api/rates?currency=USD&category=cash')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.buy', 11)
            ->assertJsonPath('data.0.rate_date', '2026-06-14');
    }

    public function test_index_excludes_inactive_banks(): void
    {
        $active = Bank::factory()->create();
        $inactive = Bank::factory()->inactive()->create();

        BankCurrencyRate::factory()->for($active, 'bank')->create(['currency' => 'USD', 'category' => 'cash']);
        BankCurrencyRate::factory()->for($inactive, 'bank')->create(['currency' => 'USD', 'category' => 'cash']);

        $this->getJson('/api/rates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bank.id', (int) $active->id);
    }

    public function test_index_shape(): void
    {
        $bank = Bank::factory()->create();
        BankCurrencyRate::factory()->for($bank, 'bank')->create(['currency' => 'EUR', 'category' => 'transfer']);

        $this->getJson('/api/rates')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['bank' => ['id', 'name_ru'], 'currency', 'category', 'buy', 'sell', 'rate_date']],
            ]);
    }

    public function test_best_buy_returns_lowest_sell(): void
    {
        // op=buy: клиент покупает валюту → лучший = минимальный sell.
        $cheap = Bank::factory()->create();
        $pricey = Bank::factory()->create();
        BankCurrencyRate::factory()->for($cheap, 'bank')->create(['currency' => 'USD', 'category' => 'cash', 'buy' => 10.0, 'sell' => 10.2]);
        BankCurrencyRate::factory()->for($pricey, 'bank')->create(['currency' => 'USD', 'category' => 'cash', 'buy' => 10.0, 'sell' => 10.9]);

        $this->getJson('/api/rates/best?currency=USD&category=cash&op=buy')
            ->assertOk()
            ->assertJsonPath('data.bank.id', (int) $cheap->id)
            ->assertJsonPath('data.sell', 10.2);
    }

    public function test_best_sell_returns_highest_buy(): void
    {
        // op=sell: клиент продаёт валюту → лучший = максимальный buy.
        $low = Bank::factory()->create();
        $high = Bank::factory()->create();
        BankCurrencyRate::factory()->for($low, 'bank')->create(['currency' => 'USD', 'category' => 'cash', 'buy' => 9.8, 'sell' => 10.5]);
        BankCurrencyRate::factory()->for($high, 'bank')->create(['currency' => 'USD', 'category' => 'cash', 'buy' => 10.3, 'sell' => 10.5]);

        $this->getJson('/api/rates/best?currency=USD&category=cash&op=sell')
            ->assertOk()
            ->assertJsonPath('data.bank.id', (int) $high->id)
            ->assertJsonPath('data.buy', 10.3);
    }

    public function test_best_requires_all_params(): void
    {
        $this->getJson('/api/rates/best?currency=USD')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category', 'op']);
    }

    public function test_invalid_category_returns_422(): void
    {
        $this->getJson('/api/rates?category=atm')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }
}
