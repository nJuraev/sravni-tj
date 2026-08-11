<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Разовая регистрация webhook-URL бота в Telegram. Запускается вручную после
 * деплоя (см. docs/railway-deploy.md) — не часть автозапуска.
 */
class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:set-webhook {url? : Полный URL до POST /api/telegram/webhook, по умолчанию APP_URL}';

    protected $description = 'Регистрирует webhook бота в Telegram Bot API (setWebhook)';

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');
        $secret = config('services.telegram.webhook_secret');

        if (empty($token) || empty($secret)) {
            $this->error('TELEGRAM_BOT_TOKEN и TELEGRAM_WEBHOOK_SECRET должны быть заданы.');

            return self::FAILURE;
        }

        $url = $this->argument('url') ?? rtrim((string) config('app.url'), '/').'/api/telegram/webhook';

        $response = Http::asJson()->post("https://api.telegram.org/bot{$token}/setWebhook", [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        $this->line($response->body());

        if (! $response->successful() || ($response->json('ok') !== true)) {
            $this->error('setWebhook failed.');

            return self::FAILURE;
        }

        $this->info("Webhook зарегистрирован: {$url}");

        return self::SUCCESS;
    }
}
