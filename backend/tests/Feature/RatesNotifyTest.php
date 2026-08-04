<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DispatchRateAlerts;
use App\Models\Bank;
use App\Models\BankCurrencyRate;
use App\Models\RateAlertSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature-тесты POST /api/internal/rates-notify и DispatchRateAlerts.
 */
class RatesNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.rates_webhook_secret', 'internal-secret');
        Config::set('services.telegram.bot_token', 'fake-token');
    }

    public function test_endpoint_without_secret_returns_403(): void
    {
        $this->postJson('/api/internal/rates-notify')->assertStatus(403);
    }

    public function test_endpoint_with_secret_dispatches_job(): void
    {
        Bus::fake();

        $this->postJson('/api/internal/rates-notify', [], ['X-Internal-Secret' => 'internal-secret'])
            ->assertStatus(202);

        Bus::assertDispatched(DispatchRateAlerts::class);
    }

    public function test_job_notifies_when_threshold_crossed_and_marks_last_notified(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $bank = Bank::factory()->create(['name_ru' => 'Банк Эсхата']);
        BankCurrencyRate::factory()->for($bank, 'bank')->create([
            'currency' => 'USD', 'category' => 'cash', 'buy' => 11.5, 'sell' => 11.8,
        ]);

        $user = User::factory()->telegram()->create();
        $alert = RateAlertSubscription::factory()->for($user)->create([
            'category' => 'cash', 'currency' => 'USD', 'op' => 'buy', 'direction' => 'above', 'threshold' => 11.0,
        ]);

        app()->call([new DispatchRateAlerts(), 'handle']);

        // Текст содержит имя банка на лучшем курсе.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'Банк Эсхата'));
        $alert->refresh();
        $this->assertEquals(11.5, (float) $alert->last_notified_value);
        $this->assertNotNull($alert->last_notified_at);
    }

    public function test_job_skips_when_below_threshold(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $bank = Bank::factory()->create();
        BankCurrencyRate::factory()->for($bank, 'bank')->create([
            'currency' => 'USD', 'category' => 'cash', 'buy' => 9.0, 'sell' => 9.3,
        ]);

        $user = User::factory()->telegram()->create();
        RateAlertSubscription::factory()->for($user)->create([
            'category' => 'cash', 'currency' => 'USD', 'op' => 'buy', 'direction' => 'above', 'threshold' => 11.0,
        ]);

        app()->call([new DispatchRateAlerts(), 'handle']);

        Http::assertNothingSent();
    }

    public function test_job_below_direction_notifies_when_under_threshold(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $bank = Bank::factory()->create();
        BankCurrencyRate::factory()->for($bank, 'bank')->create([
            'currency' => 'USD', 'category' => 'cash', 'buy' => 9.0, 'sell' => 9.3,
        ]);

        $user = User::factory()->telegram()->create();
        $alert = RateAlertSubscription::factory()->for($user)->create([
            'category' => 'cash', 'currency' => 'USD', 'op' => 'sell', 'direction' => 'below', 'threshold' => 10.0,
        ]);

        app()->call([new DispatchRateAlerts(), 'handle']);

        // sell min = 9.3 ≤ 10.0 → срабатывает.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
        $alert->refresh();
        $this->assertEquals(9.3, (float) $alert->last_notified_value);
    }

    public function test_job_does_not_repeat_notification_for_unchanged_value(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $bank = Bank::factory()->create();
        BankCurrencyRate::factory()->for($bank, 'bank')->create([
            'currency' => 'USD', 'category' => 'cash', 'buy' => 11.5, 'sell' => 11.8,
        ]);

        $user = User::factory()->telegram()->create();
        RateAlertSubscription::factory()->for($user)->create([
            'category' => 'cash', 'currency' => 'USD', 'op' => 'buy', 'direction' => 'above', 'threshold' => 11.0,
            'last_notified_value' => 11.5,
        ]);

        app()->call([new DispatchRateAlerts(), 'handle']);

        Http::assertNothingSent();
    }
}
