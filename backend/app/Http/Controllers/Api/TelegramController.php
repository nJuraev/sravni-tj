<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Инициация подписки на уведомления через Telegram-бота.
 *
 * Токен — одноразовый, живёт в Cache (не в БД): пока пользователь не нажал
 * /start в боте, никакой строки users не создаётся.
 */
class TelegramController extends Controller
{
    private const TOKEN_TTL_MINUTES = 15;

    /**
     * POST /api/telegram/subscribe-init.
     */
    public function subscribeInit(): JsonResponse
    {
        $token = Str::random(32);

        Cache::put("telegram_subscribe:{$token}", true, now()->addMinutes(self::TOKEN_TTL_MINUTES));

        $botUsername = (string) config('services.telegram.bot_username');

        return response()->json([
            'data' => [
                'deep_link' => "https://t.me/{$botUsername}?start={$token}",
                'expires_in' => self::TOKEN_TTL_MINUTES * 60,
            ],
        ]);
    }

    /**
     * GET /api/telegram/channel-link — публичная ссылка на Telegram-канал
     * для кнопки CTA на витрине. Тот же `channel_invite_link`, что уже
     * отправляется ботом вторым сообщением после /start (см.
     * TelegramWebhookController) — канал (`channel_id`), куда реально льются
     * ежедневные посты (SendFinancePostJob), а не group-id репоста статей
     * блога (`articles_group_id`, отдельный ручной триггер, см. §12.18 ТЗ).
     */
    public function channelLink(): JsonResponse
    {
        return response()->json([
            'data' => ['url' => config('services.telegram.channel_invite_link')],
        ]);
    }
}
