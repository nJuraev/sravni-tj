<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RateDigestService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Принимает update'ы Telegram Bot API (POST /api/telegram/webhook,
 * защищено middleware `telegram.webhook` — секрет проверен ДО контроллера).
 *
 * Обрабатываем только `message` (allowed_updates в setWebhook):
 *  - `/start <token>` — регистрация пользователя (токен из Cache);
 *  - нажатия кнопок меню (reply-клавиатура) — курс валют, уведомления,
 *    подбор кредита/депозита;
 *  - всё прочее (в т.ч. голый `/start`) — приветствие + меню.
 *
 * Always 200/204 к Telegram (кроме secret-mismatch — это 403 в middleware),
 * иначе Telegram будет ретраить update.
 */
class TelegramWebhookController extends Controller
{
    private const BTN_RATES = '💱 Курс валют';
    private const BTN_ALERTS = '⚙️ Уведомления';
    private const BTN_CREDIT = '🏦 Подобрать кредит';
    private const BTN_DEPOSIT = '💰 Подобрать депозит';

    public function handle(Request $request, TelegramService $telegram, RateDigestService $digest): Response
    {
        $message = $request->input('message');

        if (! is_array($message)) {
            return response()->noContent();
        }

        $text = is_string($message['text'] ?? null) ? trim($message['text']) : null;
        $from = $message['from'] ?? [];
        $chatId = $message['chat']['id'] ?? ($from['id'] ?? null);

        if ($text === null || ! is_int($chatId)) {
            return response()->noContent();
        }

        if (preg_match('/^\/start\s+(\S+)/', $text, $matches)) {
            $this->handleStart($telegram, $matches[1], $message, (int) $chatId);

            return response()->noContent();
        }

        match ($text) {
            self::BTN_RATES => $this->sendRates($telegram, $digest, (int) $chatId),
            self::BTN_ALERTS => $this->sendAlertsLink($telegram, (int) $chatId, is_int($from['id'] ?? null) ? (int) $from['id'] : null),
            self::BTN_CREDIT => $this->sendCatalog($telegram, (int) $chatId, 'credit'),
            self::BTN_DEPOSIT => $this->sendCatalog($telegram, (int) $chatId, 'deposit'),
            default => $this->sendGreeting($telegram, (int) $chatId),
        };

        return response()->noContent();
    }

    private function handleStart(TelegramService $telegram, string $token, array $message, int $chatId): void
    {
        if (! Cache::pull("telegram_subscribe:{$token}")) {
            Log::warning('Telegram /start with unknown or expired subscribe token.', ['token' => $token]);
            $this->sendGreeting($telegram, $chatId);

            return;
        }

        $from = $message['from'] ?? [];
        $telegramId = $from['id'] ?? null;

        if (! is_int($telegramId)) {
            Log::error('Telegram /start message missing from.id.', ['payload' => $message]);

            return;
        }

        $username = $from['username'] ?? null;
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));
        $name = $name !== '' ? $name : ($username ?? 'Telegram user');

        $user = User::query()->where('telegram_id', $telegramId)->first() ?? new User(['telegram_id' => $telegramId]);
        $user->name = $name;
        $user->telegram_username = $username;

        if (empty($user->api_token)) {
            $user->api_token = Str::random(64);
        }

        $user->save();

        // Сообщение 1: подтверждение + настройка уведомлений, с меню-клавиатурой.
        $telegram->sendMessage(
            $chatId,
            "Готово! Буду присылать уведомления о курсе валют.\n\n"
            ."Настрой валюту и порог в профиле:\n{$this->profileUrl($user)}",
            $this->menuKeyboard(),
        );

        // Сообщение 2: мягкий инвайт в канал (best-effort, не блокирует).
        $invite = config('services.telegram.channel_invite_link');

        if (! empty($invite)) {
            $telegram->sendMessage(
                $chatId,
                "Разбираем курсы, банки и финансовый рынок Таджикистана в нашем канале — подпишись, если интересно:\n{$invite}",
            );
        }
    }

    private function sendRates(TelegramService $telegram, RateDigestService $digest, int $chatId): void
    {
        $telegram->sendMessage(
            $chatId,
            $digest->botCashSummary(),
            $this->linkButton('Все курсы на сайте', $this->frontend('/kurs-valyut')),
        );
    }

    private function sendAlertsLink(TelegramService $telegram, int $chatId, ?int $telegramId): void
    {
        $user = $telegramId !== null
            ? User::query()->where('telegram_id', $telegramId)->first()
            : null;

        if ($user === null || empty($user->api_token)) {
            $telegram->sendMessage(
                $chatId,
                'Похоже, вы ещё не подписаны. Оформите подписку на уведомления на сайте:',
                $this->linkButton('Открыть курсы', $this->frontend('/kurs-valyut')),
            );

            return;
        }

        $telegram->sendMessage(
            $chatId,
            'Настройте валюту, сторону курса и порог уведомлений:',
            $this->linkButton('Настроить уведомления', $this->profileUrl($user)),
        );
    }

    private function sendCatalog(TelegramService $telegram, int $chatId, string $category): void
    {
        [$text, $button, $path] = $category === 'credit'
            ? ['Подбор кредита — сравните ставки банков Таджикистана и оставьте заявку на сайте.', 'Открыть кредиты', '/credit']
            : ['Подбор депозита — сравните доходность вкладов банков Таджикистана на сайте.', 'Открыть депозиты', '/deposit'];

        $telegram->sendMessage($chatId, $text, $this->linkButton($button, $this->frontend($path)));
    }

    private function sendGreeting(TelegramService $telegram, int $chatId): void
    {
        $telegram->sendMessage(
            $chatId,
            "Sravni.tj — сравнение банковских продуктов Таджикистана.\n"
            .'Выберите действие в меню ниже.',
            $this->menuKeyboard(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function menuKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => self::BTN_RATES], ['text' => self::BTN_ALERTS]],
                [['text' => self::BTN_CREDIT], ['text' => self::BTN_DEPOSIT]],
            ],
            'resize_keyboard' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkButton(string $label, string $url): array
    {
        return ['inline_keyboard' => [[['text' => $label, 'url' => $url]]]];
    }

    private function profileUrl(User $user): string
    {
        return $this->frontend("/profile?token={$user->api_token}");
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('services.telegram.frontend_url'), '/').$path;
    }
}
