<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchRateAlerts;
use Illuminate\Http\Response;

/**
 * Вызывается Go-парсером курсов (cmd/rates) после завершения прогона —
 * защищено middleware `rates.webhook` (заголовок X-Internal-Secret).
 * Сама рассылка асинхронна (queued Job), эндпоинт сразу отвечает 202.
 */
class RatesNotifyController extends Controller
{
    public function handle(): Response
    {
        DispatchRateAlerts::dispatch();

        return response()->noContent(Response::HTTP_ACCEPTED);
    }
}
