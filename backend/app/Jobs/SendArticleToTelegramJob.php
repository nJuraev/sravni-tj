<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Article;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Репост опубликованной статьи блога в Telegram-группу. Ручной триггер
 * (кнопка в /admin/articles), не авто-синк — диспатчится из
 * Admin\ArticleController::sendTelegram(). Под QUEUE_CONNECTION=sync
 * выполняется синхронно в том же процессе (см. SendFinancePostJob).
 */
class SendArticleToTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $articleId) {}

    public function handle(TelegramService $telegram): void
    {
        $article = Article::find($this->articleId);

        if ($article === null || $article->status !== 'published') {
            return;
        }

        $groupId = config('services.telegram.articles_group_id');

        if (empty($groupId)) {
            Log::error('SendArticleToTelegramJob: TELEGRAM_ARTICLES_GROUP_ID is not configured.', ['article_id' => $article->id]);

            return;
        }

        $frontendUrl = rtrim((string) config('services.telegram.frontend_url'), '/');
        $link = "{$frontendUrl}/blog/{$article->slug}";

        $excerpt = $article->excerpt_ru ?: Str::limit(strip_tags($article->body_ru), 200);

        $text = sprintf(
            "<b>%s</b>\n\n%s\n\n%s",
            e($article->title_ru),
            e($excerpt),
            $link,
        );

        $sent = $telegram->sendMessage((int) $groupId, $text, parseMode: 'HTML');

        if ($sent) {
            $article->telegram_sent_at = now();
            $article->save();
        }
    }
}
