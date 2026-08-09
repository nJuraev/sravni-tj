<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendFinancePostJob;
use App\Models\FinancePost;
use App\Services\CurrencyRateSnapshotService;
use App\Services\LlmService;
use App\Services\PostTopicSelector;
use App\Services\ProductSpotlightSelector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Единственный cron-триггер ежедневных постов в Telegram-канал (Railway
 * cronSchedule "*\/10 * * * *", см. backend/railway.telegram-posts.json).
 * На каждый прогон (раз в 10 минут, весь день):
 *  1. Если ещё не подошло время (до 04:00 UTC = 09:00 Душанбе) или сегодня
 *     уже сгенерировали пост — генерацию пропускаем.
 *  2. Иначе выбираем тип дня (PostTopicSelector::WEEKLY_PATTERN), генерируем
 *     текст через LLM, сохраняем FinancePost со случайным send_at (+1..90 мин).
 *  3. Отправляем все FinancePost со status=pending и send_at <= now() —
 *     поэтому фактическая задержка отправки = random(1..90 мин) + до ~10 мин
 *     ожидания следующего тика этого крона.
 *
 * Один процесс на весь функционал — отдельной delayed-очереди с воркером
 * не заводим: SendFinancePostJob диспатчится как обычный Job под глобальным
 * QUEUE_CONNECTION=sync (тот же приём, что и DispatchRateAlerts) — выполняется
 * синхронно в этом же вызове, без database-подключения очереди.
 *
 * Если LLM недоступен — генерация просто повторится на следующем тике (через
 * 10 минут), а не только на следующий день, как было бы при once-a-day cron.
 */
class RunFinancePostsScheduler extends Command
{
    private const GENERATE_AFTER_HOUR_UTC = 4; // 09:00 Душанбе (UTC+5)

    private const EXCERPT_MAX_CHARS = 200;

    protected $signature = 'posts:run-scheduler';

    protected $description = 'Генерирует пост дня (если ещё не сгенерирован и подошло время) и отправляет посты, чьё время пришло';

    public function handle(
        PostTopicSelector $topicSelector,
        ProductSpotlightSelector $productSelector,
        CurrencyRateSnapshotService $rateSnapshot,
        LlmService $llm,
    ): int {
        $this->maybeGenerateTodaysPost($topicSelector, $productSelector, $rateSnapshot, $llm);
        $this->dispatchDuePosts();

        return self::SUCCESS;
    }

    private function maybeGenerateTodaysPost(
        PostTopicSelector $topicSelector,
        ProductSpotlightSelector $productSelector,
        CurrencyRateSnapshotService $rateSnapshot,
        LlmService $llm,
    ): void {
        if (now()->hour < self::GENERATE_AFTER_HOUR_UTC) {
            return;
        }

        // whereIn(kind): внеплановые news-посты (Admin\FinancePostController::storeFromSource)
        // не должны блокировать обычный пост дня по расписанию.
        if (FinancePost::query()
            ->whereIn('kind', ['generic', 'product', 'currency'])
            ->whereDate('generated_at', now()->toDateString())
            ->exists()) {
            return;
        }

        $kind = PostTopicSelector::kindForDate(now());

        try {
            [$context, $postTopicId, $subjectType, $subjectId] = match ($kind) {
                'generic' => $this->buildGenericContext($topicSelector),
                'product' => $this->buildProductContext($productSelector),
                'currency' => $this->buildCurrencyContext($rateSnapshot),
            };
        } catch (RuntimeException $e) {
            Log::warning('RunFinancePostsScheduler: no content available for today.', [
                'kind' => $kind,
                'reason' => $e->getMessage(),
            ]);
            $this->warn("Пропуск генерации: {$e->getMessage()}");

            return;
        }

        try {
            $body = $llm->generatePostText($context);
        } catch (\Throwable $e) {
            Log::error('RunFinancePostsScheduler: LLM generation failed.', [
                'kind' => $kind,
                'exception' => $e->getMessage(),
            ]);
            $this->error("Генерация текста не удалась: {$e->getMessage()}");

            return;
        }

        $generatedAt = now();
        $sendAt = $generatedAt->clone()->addMinutes(random_int(1, 90));

        $post = FinancePost::create([
            'kind' => $kind,
            'post_topic_id' => $postTopicId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'body' => $body,
            'status' => 'pending',
            'generated_at' => $generatedAt,
            'send_at' => $sendAt,
        ]);

        $this->info("Пост #{$post->id} ({$kind}) сгенерирован, отправка запланирована на {$sendAt->toDateTimeString()}.");
    }

    private function dispatchDuePosts(): void
    {
        FinancePost::query()
            ->where('status', 'pending')
            ->where('send_at', '<=', now())
            ->get()
            ->each(function (FinancePost $post): void {
                SendFinancePostJob::dispatch($post->id);
                $this->info("Пост #{$post->id} отправлен.");
            });
    }

    /**
     * @return array{0: string, 1: int, 2: null, 3: null}
     */
    private function buildGenericContext(PostTopicSelector $selector): array
    {
        $topic = $selector->selectNextGeneric();

        if ($topic === null) {
            throw new RuntimeException('Нет активных generic-тем в post_topics.');
        }

        $topic->forceFill(['last_used_at' => now()])->save();

        $context = "Тема поста: {$topic->title}\n\n{$topic->prompt}";
        $context .= $this->previousPostNote(
            FinancePost::query()->where('post_topic_id', $topic->id)->latest('generated_at')->first(),
        );

        return [$context, $topic->id, null, null];
    }

    /**
     * @return array{0: string, 1: null, 2: string, 3: int}
     */
    private function buildProductContext(ProductSpotlightSelector $selector): array
    {
        $product = $selector->selectNext();

        if ($product === null) {
            throw new RuntimeException('Нет активных продуктов для «дня продукта».');
        }

        $bankName = $product->bank->name_ru ?? '—';
        $productName = $product->name_ru ?? $product->name_tg ?? 'продукт';
        $features = array_keys(array_filter($product->features ?? []));

        $lines = [
            'Расскажи о конкретном банковском продукте (сделай его героем поста):',
            "Банк: {$bankName}",
            "Продукт: {$productName}",
            "Категория: {$product->category}".($product->subcategory ? " / {$product->subcategory}" : ''),
            "Валюта: {$product->currency}",
            "Ставка: {$product->rate_min}–{$product->rate_max}% годовых",
        ];

        if ($product->amount_min !== null || $product->amount_max !== null) {
            $lines[] = 'Сумма: '.($product->amount_min ?? '—').' – '.($product->amount_max ?? '—').' '.$product->currency;
        }

        if ($product->term_min !== null || $product->term_max !== null) {
            $lines[] = 'Срок: '.($product->term_min ?? '—').' – '.($product->term_max ?? '—').' мес.';
        }

        if ($features !== []) {
            $lines[] = 'Особенности: '.implode(', ', $features);
        }

        $lines[] = 'В конце мягко предложи сравнить с другими предложениями на sravni.tj.';

        $context = implode("\n", $lines);
        $context .= $this->previousPostNote(
            FinancePost::query()->where('subject_type', 'product')->where('subject_id', $product->id)->latest('generated_at')->first(),
        );

        return [$context, null, 'product', $product->id];
    }

    /**
     * @return array{0: string, 1: null, 2: null, 3: null}
     */
    private function buildCurrencyContext(CurrencyRateSnapshotService $service): array
    {
        $snapshot = $service->latestSnapshot();

        if (! $service->hasData($snapshot)) {
            throw new RuntimeException('Нет данных по курсам валют в bank_currency_rates.');
        }

        $context = $service->toPromptContext($snapshot)
            ."\n\nНапиши пост о текущих курсах валют, используя ТОЛЬКО эти цифры.";

        return [$context, null, null, null];
    }

    /**
     * Кусок предыдущего поста на ту же тему/продукт — не весь текст (лишние
     * токены), только начало (первый абзац, максимум EXCERPT_MAX_CHARS),
     * чтобы LLM не повторялся дословно, но не копировал прошлый пост целиком.
     */
    private function previousPostNote(?FinancePost $previous): string
    {
        if ($previous === null) {
            return '';
        }

        $excerpt = $this->excerpt($previous->body);

        return "\n\nРанее уже был пост на эту тему, он начинался так: «{$excerpt}…». ".
            'Напиши новый — с другим примером или углом, не повторяй дословно.';
    }

    private function excerpt(string $body): string
    {
        $body = trim($body);
        $paragraphEnd = mb_strpos($body, "\n\n");
        $excerpt = $paragraphEnd !== false ? mb_substr($body, 0, $paragraphEnd) : $body;

        if (mb_strlen($excerpt) > self::EXCERPT_MAX_CHARS) {
            $excerpt = mb_substr($excerpt, 0, self::EXCERPT_MAX_CHARS);
        }

        return trim($excerpt);
    }
}
