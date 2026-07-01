<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Управление пользователями админ-панели. Доступно только роли admin
 * (проверка в каждом методе; editor получает 403).
 */
class UserController extends Controller
{
    /**
     * GET /api/admin/users.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $users = AdminUser::query()->orderBy('name')->get();

        return AdminUserResource::collection($users)->response();
    }

    /**
     * POST /api/admin/users.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('admin_users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'editor'])],
            'is_active' => ['boolean'],
        ]);

        $user = AdminUser::create($data);

        return response()->json(['data' => new AdminUserResource($user)], Response::HTTP_CREATED);
    }

    /**
     * PUT/PATCH /api/admin/users/{user}.
     */
    public function update(Request $request, AdminUser $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('admin_users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'editor'])],
            'is_active' => ['boolean'],
        ]);

        // Нельзя снять с себя роль admin или деактивировать себя — иначе можно
        // случайно лишить себя доступа к разделу пользователей.
        if ($user->id === $request->user('admin')->id) {
            if (($data['role'] ?? 'admin') !== 'admin' || ($data['is_active'] ?? true) === false) {
                throw ValidationException::withMessages([
                    'role' => ['Нельзя понизить или деактивировать собственный аккаунт.'],
                ]);
            }
        }

        // Пустой пароль = не менять.
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->fill($data)->save();

        return response()->json(['data' => new AdminUserResource($user)]);
    }

    /**
     * DELETE /api/admin/users/{user}.
     */
    public function destroy(Request $request, AdminUser $user): Response
    {
        $this->authorizeAdmin($request);

        if ($user->id === $request->user('admin')->id) {
            throw ValidationException::withMessages([
                'id' => ['Нельзя удалить собственный аккаунт.'],
            ]);
        }

        $user->delete();

        return response()->noContent();
    }

    /**
     * Только роль admin управляет пользователями.
     */
    private function authorizeAdmin(Request $request): void
    {
        /** @var AdminUser $actor */
        $actor = $request->user('admin');
        abort_unless($actor->isAdmin(), Response::HTTP_FORBIDDEN, 'Недостаточно прав.');
    }
}
