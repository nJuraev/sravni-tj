<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\BankCurrencyRate;
use App\Models\RateAlertSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature-тесты мастера настройки алерта прямо в Telegram-чате (без сайта):
 * ⚙️ Настроить уведомления → callback валюта → callback купить/продать → текст порога.
 */
class TelegramAlertWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', 'fake-token');
        Config::set('services.telegram.webhook_secret', 'test-secret');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    private function postMessage(string $text, int $telegramId): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/telegram/webhook', [
            'message' => [
                'text' => $text,
                'from' => ['id' => $telegramId, 'first_name' => 'Ivan'],
                'chat' => ['id' => $telegramId],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']);
    }

    private function postCallback(string $data, int $telegramId): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => $data,
                'from' => ['id' => $telegramId],
                'message' => ['chat' => ['id' => $telegramId]],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']);
    }

    private function seedUsdCashRate(float $buy = 11.0, float $sell = 11.2): void
    {
        $bank = Bank::factory()->create();
        BankCurrencyRate::factory()->for($bank, 'bank')->create([
            'currency' => 'USD', 'category' => 'cash', 'buy' => $buy, 'sell' => $sell,
        ]);
    }

    public function test_full_wizard_creates_alert_with_buy_intent(): void
    {
        $this->seedUsdCashRate(buy: 11.0, sell: 11.2);
        $user = User::factory()->telegram()->create(['telegram_id' => 700]);

        $this->postMessage('⚙️ Настроить уведомления', 700)->assertNoContent();
        $this->assertSame(['step' => 'currency'], Cache::get('alert_wizard:700'));

        $this->postCallback('aw:c:USD', 700)->assertNoContent();
        $this->assertSame(['step' => 'intent', 'currency' => 'USD'], Cache::get('alert_wizard:700'));

        // "Хочу купить" → op=sell (банк продаёт клиенту), direction=below.
        $this->postCallback('aw:i:buy', 700)->assertNoContent();
        $this->assertSame(
            ['step' => 'threshold', 'currency' => 'USD', 'op' => 'sell', 'direction' => 'below'],
            Cache::get('alert_wizard:700'),
        );

        // sell=11.2, ±50% → [5.6, 16.8]. 10.5 в диапазоне.
        $this->postMessage('10.5', 700)->assertNoContent();

        $this->assertNull(Cache::get('alert_wizard:700'));
        $this->assertDatabaseHas('rate_alert_subscriptions', [
            'user_id' => $user->id,
            'category' => 'cash',
            'currency' => 'USD',
            'op' => 'sell',
            'direction' => 'below',
            'threshold' => 10.5,
        ]);

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Готово!'));
    }

    public function test_sell_intent_maps_to_buy_op_and_above_direction(): void
    {
        $this->seedUsdCashRate();
        User::factory()->telegram()->create(['telegram_id' => 701]);

        $this->postMessage('⚙️ Настроить уведомления', 701)->assertNoContent();
        $this->postCallback('aw:c:USD', 701)->assertNoContent();
        $this->postCallback('aw:i:sell', 701)->assertNoContent();

        $this->assertSame(
            ['step' => 'threshold', 'currency' => 'USD', 'op' => 'buy', 'direction' => 'above'],
            Cache::get('alert_wizard:701'),
        );
    }

    public function test_threshold_outside_50_percent_band_is_rejected_and_wizard_stays_open(): void
    {
        $this->seedUsdCashRate(buy: 11.0, sell: 11.2);
        User::factory()->telegram()->create(['telegram_id' => 702]);

        $this->postMessage('⚙️ Настроить уведомления', 702)->assertNoContent();
        $this->postCallback('aw:c:USD', 702)->assertNoContent();
        $this->postCallback('aw:i:buy', 702)->assertNoContent();

        // Референс sell=11.2 → верхняя граница 16.8. 20 — за пределами.
        $this->postMessage('20', 702)->assertNoContent();

        $this->assertDatabaseCount('rate_alert_subscriptions', 0);
        $this->assertNotNull(Cache::get('alert_wizard:702'));
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'пределах'));
    }

    public function test_non_numeric_threshold_reply_is_rejected(): void
    {
        $this->seedUsdCashRate();
        User::factory()->telegram()->create(['telegram_id' => 703]);

        $this->postMessage('⚙️ Настроить уведомления', 703)->assertNoContent();
        $this->postCallback('aw:c:USD', 703)->assertNoContent();
        $this->postCallback('aw:i:buy', 703)->assertNoContent();

        $this->postMessage('не число', 703)->assertNoContent();

        $this->assertDatabaseCount('rate_alert_subscriptions', 0);
        $this->assertNotNull(Cache::get('alert_wizard:703'));
    }

    public function test_wizard_declines_to_start_when_user_already_has_three_alerts(): void
    {
        $this->seedUsdCashRate();
        $user = User::factory()->telegram()->create(['telegram_id' => 704]);
        foreach ([['currency' => 'USD', 'op' => 'buy'], ['currency' => 'EUR', 'op' => 'buy'], ['currency' => 'USD', 'op' => 'sell']] as $attrs) {
            RateAlertSubscription::factory()->for($user)->create(['category' => 'cash', 'direction' => 'above', ...$attrs]);
        }

        $this->postMessage('⚙️ Настроить уведомления', 704)->assertNoContent();

        $this->assertNull(Cache::get('alert_wizard:704'));
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'максимум'));
    }

    public function test_unknown_user_is_prompted_to_subscribe_instead_of_starting_wizard(): void
    {
        $this->postMessage('⚙️ Настроить уведомления', 999)->assertNoContent();

        $this->assertNull(Cache::get('alert_wizard:999'));
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), '/kurs-valyut'));
    }

    public function test_callback_without_active_wizard_state_is_ignored(): void
    {
        User::factory()->telegram()->create(['telegram_id' => 705]);

        $this->postCallback('aw:c:USD', 705)->assertNoContent();

        $this->assertDatabaseCount('rate_alert_subscriptions', 0);
        // answerCallbackQuery всё равно должен быть отправлен (ack).
        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery'));
    }

    public function test_duplicate_alert_via_wizard_is_rejected(): void
    {
        $this->seedUsdCashRate(buy: 11.0, sell: 11.2);
        $user = User::factory()->telegram()->create(['telegram_id' => 706]);
        RateAlertSubscription::factory()->for($user)->create([
            'category' => 'cash', 'currency' => 'USD', 'op' => 'sell', 'direction' => 'below', 'threshold' => 10,
        ]);

        $this->postMessage('⚙️ Настроить уведомления', 706)->assertNoContent();
        $this->postCallback('aw:c:USD', 706)->assertNoContent();
        $this->postCallback('aw:i:buy', 706)->assertNoContent();
        $this->postMessage('10.5', 706)->assertNoContent();

        $this->assertDatabaseCount('rate_alert_subscriptions', 1);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'уже настроено'));
    }
}
