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
     */
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, bool $disableWebPagePreview = true): bool
    {
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
}
