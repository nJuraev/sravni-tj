<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверяет внутренний секрет вызова Go-парсера курсов (POST
 * /api/internal/rates-notify) — заголовок X-Internal-Secret должен
 * совпадать с TELEGRAM_RATES_WEBHOOK_SECRET. Несовпадение/отсутствие — 403.
 */
class VerifyRatesWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.telegram.rates_webhook_secret');
        $received = $request->header('X-Internal-Secret');

        if (empty($expected) || $received !== $expected) {
            abort(403);
        }

        return $next($request);
    }
}
