<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Тонкий клиент для генерации текста постов. Провайдер выбирается конфигом
 * (services.ai.provider), теми же именами env-переменных, что у Go-парсера
 * (AI_PROVIDER/AI_API_KEY/AI_MODEL) — можно переиспользовать тот же ключ,
 * что уже настроен для парсера, вместо отдельной регистрации на OpenRouter.
 * Оба провайдера — OpenAI-совместимый chat/completions.
 */
class LlmService
{
    private const ENDPOINTS = [
        'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
        'deepseek' => 'https://api.deepseek.com/chat/completions',
    ];

    private const DEFAULT_MODELS = [
        'openrouter' => 'deepseek/deepseek-chat-v3.1:free',
        'deepseek' => 'deepseek-v4-flash',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
Ты — редактор Telegram-канала sravni.tj (агрегатор банковских продуктов Таджикистана).
Пиши пост на русском языке, до 800 символов, живым и понятным языком, без канцелярита.
Не используй Markdown-разметку, не поддерживаемую Telegram (заголовки #, таблицы) —
разрешены только эмодзи и обычные абзацы. Не придумывай цифры, ставки или курсы —
используй только те, что даны в контексте пользователя. Не добавляй хэштеги.
PROMPT;

    /**
     * @throws RuntimeException при неподдерживаемом провайдере, отсутствии ключа или неуспешном ответе
     */
    public function generatePostText(string $context): string
    {
        $provider = (string) config('services.ai.provider', 'openrouter');
        $endpoint = self::ENDPOINTS[$provider] ?? null;

        if ($endpoint === null) {
            throw new RuntimeException("Неизвестный AI_PROVIDER: {$provider} (ожидается openrouter|deepseek).");
        }

        $apiKey = config('services.ai.api_key');

        if (empty($apiKey)) {
            throw new RuntimeException('AI_API_KEY не задан.');
        }

        $model = config('services.ai.model') ?: self::DEFAULT_MODELS[$provider];

        try {
            $response = Http::asJson()
                ->withToken($apiKey)
                ->timeout(30)
                ->post($endpoint, [
                    'model' => $model,
                    'temperature' => 0.7,
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => $context],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('LlmService: request threw an exception.', ['provider' => $provider, 'exception' => $e->getMessage()]);

            throw new RuntimeException('AI request failed.', previous: $e);
        }

        if (! $response->successful()) {
            Log::error('LlmService: request failed.', [
                'provider' => $provider,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("AI request failed: HTTP {$response->status()}");
        }

        $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

        if ($text === '') {
            throw new RuntimeException('AI returned empty post text.');
        }

        return $text;
    }
}
