<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FinancePost;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Отправка сгенерированного поста в Telegram-канал. Диспатчится обычным
 * `::dispatch()` из RunFinancePostsScheduler — под глобальным
 * QUEUE_CONNECTION=sync выполняется синхронно в том же процессе (тот же
 * приём, что и DispatchRateAlerts), без отдельной очереди/воркера.
 */
class SendFinancePostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $financePostId) {}

    public function handle(TelegramService $telegram): void
    {
        $post = FinancePost::find($this->financePostId);

        if ($post === null || $post->status !== 'pending') {
            return;
        }

        $channelId = config('services.telegram.channel_id');

        if (empty($channelId)) {
            Log::error('SendFinancePostJob: telegram channel_id is not configured.', ['post_id' => $post->id]);

            $post->forceFill(['status' => 'failed', 'error' => 'TELEGRAM_CHANNEL_ID is not configured.'])->save();

            return;
        }

        try {
            $sent = $telegram->sendMessage(
                (int) $channelId,
                $post->body,
                parseMode: $post->kind === 'currency' ? 'HTML' : null,
            );
        } catch (\Throwable $e) {
            $post->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();

            throw $e;
        }

        if (! $sent) {
            $post->forceFill(['status' => 'failed', 'error' => 'TelegramService::sendMessage returned false.'])->save();

            return;
        }

        $post->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
    }
}
