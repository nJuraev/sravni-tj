<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\BankCurrencyRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature-тесты подписки на уведомления через Telegram:
 * POST /api/telegram/subscribe-init, POST /api/telegram/webhook.
 */
class TelegramSubscribeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', 'fake-token');
        Config::set('services.telegram.bot_username', 'sravni_test_bot');
        Config::set('services.telegram.webhook_secret', 'test-secret');
        Config::set('services.telegram.channel_invite_link', 'https://t.me/sravni_channel');
        Config::set('services.telegram.frontend_url', 'https://sravni.tj');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    public function test_subscribe_init_returns_deep_link_with_cached_token(): void
    {
        $response = $this->postJson('/api/telegram/subscribe-init')
            ->assertOk()
            ->assertJsonStructure(['data' => ['deep_link', 'expires_in']]);

        $deepLink = $response->json('data.deep_link');
        $this->assertStringStartsWith('https://t.me/sravni_test_bot?start=', $deepLink);

        $token = substr($deepLink, strrpos($deepLink, '=') + 1);
        $this->assertTrue(Cache::has("telegram_subscribe:{$token}"));
    }

    public function test_webhook_without_secret_header_returns_403(): void
    {
        $this->postJson('/api/telegram/webhook', ['message' => ['text' => '/start abc']])
            ->assertStatus(403);
    }

    public function test_webhook_with_wrong_secret_returns_403(): void
    {
        $this->postJson('/api/telegram/webhook', ['message' => ['text' => '/start abc']], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ])->assertStatus(403);
    }

    public function test_valid_start_creates_user_with_api_token(): void
    {
        Cache::put('telegram_subscribe:tok123', true, now()->addMinutes(15));

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'text' => '/start tok123',
                'from' => ['id' => 555, 'first_name' => 'Ivan', 'username' => 'ivan_tj'],
                'chat' => ['id' => 555],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertNoContent();

        $user = User::query()->where('telegram_id', 555)->first();
        $this->assertNotNull($user);
        $this->assertSame('ivan_tj', $user->telegram_username);
        $this->assertNotEmpty($user->api_token);
        $this->assertFalse(Cache::has('telegram_subscribe:tok123'));

        // Два сообщения: подтверждение с меню + мягкий инвайт в канал.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Уведомления'));
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'https://t.me/sravni_channel'));
    }

    public function test_start_without_channel_link_skips_invite_message(): void
    {
        Config::set('services.telegram.channel_invite_link', null);
        Cache::put('telegram_subscribe:tok456', true, now()->addMinutes(15));

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'text' => '/start tok456',
                'from' => ['id' => 556, 'first_name' => 'Anvar'],
                'chat' => ['id' => 556],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertNoContent();

        // Только сообщение о настройке уведомлений, инвайта нет.
        Http::assertSentCount(1);
    }

    private function postButton(string $text, int $telegramId = 555): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/telegram/webhook', [
            'message' => [
                'text' => $text,
                'from' => ['id' => $telegramId, 'first_name' => 'Ivan'],
                'chat' => ['id' => $telegramId],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']);
    }

    public function test_rates_button_returns_best_rate_and_site_link(): void
    {
        $bank = Bank::factory()->create(['name_ru' => 'Банк Эсхата']);
        BankCurrencyRate::factory()->for($bank, 'bank')->create([
            'currency' => 'USD', 'category' => 'cash', 'buy' => 11.5, 'sell' => 11.8,
        ]);

        $this->postButton('💱 Курс валют')->assertNoContent();

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Банк Эсхата')
            && str_contains(json_encode($request->data()), '/kurs-valyut'));
    }

    public function test_alerts_button_starts_currency_wizard_for_registered_user(): void
    {
        User::factory()->telegram()->create(['telegram_id' => 606, 'api_token' => 'tok-606']);
        $bank = Bank::factory()->create();
        BankCurrencyRate::factory()->for($bank, 'bank')->create(['currency' => 'USD', 'category' => 'cash']);

        $this->postButton('⚙️ Уведомления', 606)->assertNoContent();

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'валюту')
            && str_contains(json_encode($request->data()), 'aw:c:USD'));
        $this->assertSame(['step' => 'currency'], Cache::get('alert_wizard:606'));
    }

    public function test_alerts_button_prompts_signup_for_unknown_user(): void
    {
        $this->postButton('⚙️ Уведомления', 999)->assertNoContent();

        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), '/kurs-valyut'));
    }

    public function test_credit_button_returns_catalog_link(): void
    {
        $this->postButton('🏦 Подобрать кредит')->assertNoContent();

        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), '/credit'));
    }

    public function test_start_with_unknown_token_does_not_create_user(): void
    {
        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'text' => '/start unknown-token',
                'from' => ['id' => 777, 'first_name' => 'Ghost'],
                'chat' => ['id' => 777],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertNoContent();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_repeat_start_does_not_rotate_existing_api_token(): void
    {
        Cache::put('telegram_subscribe:first', true, now()->addMinutes(15));
        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'text' => '/start first',
                'from' => ['id' => 999, 'first_name' => 'Repeat'],
                'chat' => ['id' => 999],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertNoContent();

        $firstToken = User::query()->where('telegram_id', 999)->firstOrFail()->api_token;

        Cache::put('telegram_subscribe:second', true, now()->addMinutes(15));
        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'text' => '/start second',
                'from' => ['id' => 999, 'first_name' => 'Repeat'],
                'chat' => ['id' => 999],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertNoContent();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($firstToken, User::query()->where('telegram_id', 999)->firstOrFail()->api_token);
    }
}
