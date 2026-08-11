<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Тонкая обёртка над Telegram Bot API. Используется webhook-контроллером
 * (ответ на /start) и рассылкой алертов по курсу — единая точка отправки
 * сообщений и логирования сбоев.
 */
class TelegramService
{
    /**
     * @param  array<string, mixed>|null  $replyMarkup  reply_markup (inline/reply keyboard)
     * @param  string|null  $parseMode  'HTML' — для сообщений с разметкой (напр. <pre> таблица курсов)
     */
    public function sendMessage(
        int $chatId,
        string $text,
        ?array $replyMarkup = null,
        bool $disableWebPagePreview = true,
        ?string $parseMode = null,
    ): bool {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            Log::warning('Telegram bot_token is not configured; message not sent.', ['chat_id' => $chatId]);

            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => $disableWebPagePreview,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $response = Http::asJson()
                ->timeout(5)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

            if (! $response->successful()) {
                Log::error('Telegram sendMessage failed.', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram sendMessage threw an exception.', [
                'chat_id' => $chatId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Обязателен после тапа на inline-кнопку — иначе у пользователя кнопка
     * "крутится" (Telegram ждёт ack), даже если текст всплывающей подсказки
     * не нужен.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            return false;
        }

        try {
            $response = Http::asJson()
                ->timeout(5)
                ->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", array_filter([
                    'callback_query_id' => $callbackQueryId,
                    'text' => $text,
                ]));

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Telegram answerCallbackQuery threw an exception.', [
                'callback_query_id' => $callbackQueryId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
