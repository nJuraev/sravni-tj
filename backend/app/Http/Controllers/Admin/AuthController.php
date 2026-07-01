<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Аутентификация админ-панели (token-guard).
 *
 * Логин выдаёт api_token; клиент шлёт его как Authorization: Bearer <token>.
 * Логаут обнуляет токен (инвалидация сессии).
 */
class AuthController extends Controller
{
    /**
     * POST /api/admin/login.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var AdminUser|null $user */
        $user = AdminUser::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Неверный email или пароль.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Аккаунт деактивирован.'],
            ]);
        }

        $token = Str::random(64);
        $user->forceFill(['api_token' => $token])->save();

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new AdminUserResource($user),
            ],
        ]);
    }

    /**
     * GET /api/admin/me.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new AdminUserResource($request->user('admin')),
        ]);
    }

    /**
     * POST /api/admin/logout.
     */
    public function logout(Request $request): Response
    {
        /** @var AdminUser $user */
        $user = $request->user('admin');
        $user->forceFill(['api_token' => null])->save();

        return response()->noContent();
    }
}
