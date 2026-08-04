<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверяет, что запрос на /api/telegram/webhook действительно пришёл от
 * Telegram: секрет задаётся при setWebhook (secret_token) и должен совпадать
 * с заголовком X-Telegram-Bot-Api-Secret-Token. Несовпадение/отсутствие —
 * 403, до контроллера запрос не доходит.
 */
class VerifyTelegramWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.telegram.webhook_secret');
        $received = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (empty($expected) || $received !== $expected) {
            abort(403);
        }

        return $next($request);
    }
}
