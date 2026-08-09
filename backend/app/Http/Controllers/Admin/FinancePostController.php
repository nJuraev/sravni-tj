<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminFinancePostResource;
use App\Models\FinancePost;
use App\Services\LlmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * История сгенерированных/отправленных постов (админка) — просмотр
 * (посты по расписанию не редактируются вручную) + внеплановые news-посты
 * по внешнему источнику (storeFromSource).
 */
class FinancePostController extends Controller
{
    /**
     * GET /api/admin/finance-posts.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $posts = FinancePost::query()
            ->with('topic:id,title')
            ->orderByDesc('send_at')
            ->paginate($perPage);

        return AdminFinancePostResource::collection($posts)->response();
    }

    /**
     * POST /api/admin/finance-posts/from-source — внеплановый пост по внешнему
     * источнику (например, новость Нацбанка): админ вставляет заголовок/текст,
     * LLM пересказывает своими словами (не публикует исходник дословно —
     * стиль канала + авторские права). Без предпросмотра — сразу status=pending
     * со случайным send_at, как и автоматические посты (см. RunFinancePostsScheduler).
     */
    public function storeFromSource(Request $request, LlmService $llm): JsonResponse
    {
        $data = $request->validate([
            'source_title' => ['nullable', 'string', 'max:255'],
            'source_text' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        $context = 'Источник для поста (перескажи своими словами, НЕ копируй дословно; '.
            'если уместно, укажи в начале что-то вроде "по данным ...", не выдумывая источник, '.
            "которого нет в тексте ниже):\n\n".
            ($data['source_title'] ? "Заголовок: {$data['source_title']}\n\n" : '').
            $data['source_text']."\n\n".
            'Напиши на основе этого пост для Telegram-канала sravni.tj — в своём стиле, '.
            'с выводом для читателя: как это касается вкладов, кредитов или курсов валют в Таджикистане.';

        try {
            $body = $llm->generatePostText($context);
        } catch (\Throwable $e) {
            // 502, не 422 — это сбой апстрима (LLM), не ошибка валидации формы
            // (иначе фронт спутал бы это с полевыми ошибками, у которых тот же статус).
            return response()->json(['message' => "Генерация не удалась: {$e->getMessage()}"], Response::HTTP_BAD_GATEWAY);
        }

        $generatedAt = now();

        $post = FinancePost::create([
            'kind' => 'news',
            'body' => $body,
            'status' => 'pending',
            'generated_at' => $generatedAt,
            'send_at' => $generatedAt->clone()->addMinutes(random_int(1, 90)),
        ]);

        return response()->json(['data' => new AdminFinancePostResource($post)], Response::HTTP_CREATED);
    }
}
