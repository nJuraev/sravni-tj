<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Профиль пользователя, зарегистрированного через Telegram (guard `user`,
 * см. config/auth.php). Идентификация — Authorization: Bearer <api_token>.
 */
class ProfileController extends Controller
{
    /**
     * GET /api/profile.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new ProfileResource($request->user('user')),
        ]);
    }

    /**
     * PATCH /api/profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user');
        $user->update($request->validated());

        return response()->json([
            'data' => new ProfileResource($user),
        ]);
    }
}
