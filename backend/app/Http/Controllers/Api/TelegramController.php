<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * GET /api/telegram/articles-group-link — публичная ссылка на группу,
     * где публикуются посты блога (см. SendArticleToTelegramJob), для кнопки
     * CTA на витрине. Группа задана по chat_id (`articles_group_id`), не по
     * ссылке, поэтому ссылка получается через Bot API и кэшируется навсегда:
     * `exportChatInviteLink` при каждом вызове отзывает предыдущую ссылку, и
     * дёргать его часто нельзя — ссылка перевыпустится, только если кэш
     * вручную сбросить (напр. `php artisan cache:forget telegram_articles_group_link`).
     */
    public function articlesGroupLink(): JsonResponse
    {
        $groupId = config('services.telegram.articles_group_id');
        $botToken = config('services.telegram.bot_token');

        if (empty($groupId) || empty($botToken)) {
            return response()->json(['data' => ['url' => null]]);
        }

        $url = Cache::rememberForever(
            'telegram_articles_group_link',
            fn () => $this->fetchArticlesGroupLink((string) $botToken, (string) $groupId),
        );

        return response()->json(['data' => ['url' => $url]]);
    }

    private function fetchArticlesGroupLink(string $botToken, string $groupId): ?string
    {
        try {
            // Публичная группа (есть @username) — не требует invite-ссылки и ничего не отзывает.
            $chat = Http::timeout(5)->get("https://api.telegram.org/bot{$botToken}/getChat", ['chat_id' => $groupId]);
            $username = $chat->successful() ? $chat->json('result.username') : null;

            if (! empty($username)) {
                return "https://t.me/{$username}";
            }

            // Приватная группа — единственный способ получить публичную ссылку.
            $invite = Http::timeout(5)->get("https://api.telegram.org/bot{$botToken}/exportChatInviteLink", ['chat_id' => $groupId]);

            if ($invite->successful()) {
                return $invite->json('result');
            }

            Log::error('Telegram articles group link: API call failed.', [
                'getChat_status' => $chat->status(),
                'exportChatInviteLink_status' => $invite->status(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram articles group link fetch threw an exception.', ['exception' => $e->getMessage()]);
        }

        return null;
    }
}
