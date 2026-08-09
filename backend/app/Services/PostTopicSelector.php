<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PostTopic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Планирование постов на неделю: тип дня (generic/product/currency) —
 * детерминированно по дню недели (WEEKLY_PATTERN), тема generic-дня —
 * LRU по post_topics.last_used_at (самая давно/никогда не использованная —
 * следующая). При активном пуле из N тем интервал повтора темы = N дней.
 *
 * Тай-брейк внутри группы с одинаковым last_used_at — случайный, чтобы новые
 * темы (last_used_at = NULL при первом заполнении пула) не шли в жёстко
 * фиксированном порядке на все циклы вперёд.
 */
class PostTopicSelector
{
    /**
     * Индекс — Illuminate\Support\Carbon::dayOfWeek (0=Вс, 1=Пн, …, 6=Сб).
     */
    public const WEEKLY_PATTERN = [
        0 => 'generic',  // Вс
        1 => 'generic',  // Пн
        2 => 'product',  // Вт
        3 => 'generic',  // Ср
        4 => 'currency', // Чт
        5 => 'generic',  // Пт
        6 => 'product',  // Сб
    ];

    public static function kindForDate(Carbon $date): string
    {
        return self::WEEKLY_PATTERN[$date->dayOfWeek];
    }

    /**
     * Выбирает следующую generic-тему. Не трогает last_used_at — вызывающий
     * код обновляет его сам после фактической генерации поста.
     */
    public function selectNextGeneric(): ?PostTopic
    {
        $topics = PostTopic::query()->active()->get();

        if ($topics->isEmpty()) {
            return null;
        }

        return $this->pickLeastRecentlyUsed($topics)->random();
    }

    /**
     * Превью следующих $days дней без побочных эффектов (не пишет в БД —
     * симулирует LRU на клонированных данных).
     *
     * @return array<int, array{date: string, kind: string, topic_title: string|null}>
     */
    public function previewWeek(int $days = 7): array
    {
        // Легковесные клоны (id/title/last_used_at) — мутируем last_used_at
        // по ходу симуляции, реальные модели/БД не трогаем.
        $simulated = PostTopic::query()->active()->get(['id', 'title', 'last_used_at'])
            ->map(fn (PostTopic $topic): object => (object) [
                'title' => $topic->title,
                'last_used_at' => $topic->last_used_at,
            ])
            ->values();

        $result = [];

        for ($i = 0; $i < $days; $i++) {
            $date = now()->addDays($i);
            $kind = self::kindForDate($date);

            if ($kind !== 'generic' || $simulated->isEmpty()) {
                $result[] = ['date' => $date->toDateString(), 'kind' => $kind, 'topic_title' => null];

                continue;
            }

            $chosen = $this->pickLeastRecentlyUsed($simulated)->random();
            $chosen->last_used_at = $date;

            $result[] = ['date' => $date->toDateString(), 'kind' => 'generic', 'topic_title' => $chosen->title];
        }

        return $result;
    }

    /**
     * @param  Collection<int, object>  $topics
     * @return Collection<int, object>
     */
    private function pickLeastRecentlyUsed(Collection $topics): Collection
    {
        $priority = fn (object $topic): int => $topic->last_used_at?->getTimestamp() ?? -1;

        $minPriority = $topics->min($priority);

        return $topics->filter(fn (object $topic): bool => $priority($topic) === $minPriority)->values();
    }
}
