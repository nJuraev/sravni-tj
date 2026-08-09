<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminPostTopicResource;
use App\Models\PostTopic;
use App\Services\PostTopicSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * CRUD generic-тем для ежедневных постов (админка). Новая тема получает
 * last_used_at = NULL и автоматически попадает в приоритетную очередь LRU
 * (см. PostTopicSelector) — отдельного механизма «вставить в начало» нет.
 */
class PostTopicController extends Controller
{
    /**
     * GET /api/admin/post-topics.
     */
    public function index(): JsonResponse
    {
        $topics = PostTopic::query()->orderByDesc('created_at')->get();

        return AdminPostTopicResource::collection($topics)->response();
    }

    /**
     * POST /api/admin/post-topics.
     */
    public function store(Request $request): JsonResponse
    {
        $topic = PostTopic::create($this->validateData($request));

        return response()->json(['data' => new AdminPostTopicResource($topic)], Response::HTTP_CREATED);
    }

    /**
     * PUT/PATCH /api/admin/post-topics/{postTopic}.
     */
    public function update(Request $request, PostTopic $postTopic): JsonResponse
    {
        $postTopic->update($this->validateData($request));

        return response()->json(['data' => new AdminPostTopicResource($postTopic)]);
    }

    /**
     * DELETE /api/admin/post-topics/{postTopic}.
     */
    public function destroy(PostTopic $postTopic): Response
    {
        $postTopic->delete();

        return response()->noContent();
    }

    /**
     * GET /api/admin/post-topics/preview — что уйдёт в канал в ближайшие 7 дней.
     */
    public function preview(PostTopicSelector $selector): JsonResponse
    {
        return response()->json(['data' => $selector->previewWeek(7)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
