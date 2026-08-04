<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRateAlertRequest;
use App\Http\Resources\RateAlertResource;
use App\Models\RateAlertSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Управление алертами по курсу текущего telegram-пользователя (guard `user`).
 */
class RateAlertController extends Controller
{
    /**
     * GET /api/profile/alerts.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user');

        return response()->json([
            'data' => RateAlertResource::collection(
                $user->rateAlertSubscriptions()->latest()->get()
            ),
        ]);
    }

    /**
     * POST /api/profile/alerts.
     */
    public function store(StoreRateAlertRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user');

        $alert = $user->rateAlertSubscriptions()->create($request->validated());

        return response()->json(['data' => new RateAlertResource($alert)], Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/profile/alerts/{alert}.
     */
    public function destroy(Request $request, RateAlertSubscription $alert): Response
    {
        if ($alert->user_id !== $request->user('user')->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $alert->delete();

        return response()->noContent();
    }
}
