<?php

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Локаль для сообщений об ошибках валидации по Accept-Language (ru/tg).
        $middleware->api(append: [
            SetLocaleFromHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Все ошибки под /api/* отдаём как JSON в едином формате контракта
        // (docs/api/contracts.md «Формат ошибок»). Валидация (422) обрабатывается
        // стандартным рендером Laravel и уже соответствует контракту
        // ({"message": ..., "errors": {...}}), поэтому её не перехватываем.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Resource not found.'], Response::HTTP_NOT_FOUND);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Method not allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
            }
        });

        // Любая прочая внутренняя ошибка под /api/* — без раскрытия деталей реализации.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Не вмешиваемся в исключения, у которых уже есть корректный рендер/статус:
            //  - ValidationException → 422 в формате контракта (рендерит Laravel);
            //  - HttpException (404/405/прочие http) → собственный статус;
            //  - AuthenticationException → 401.
            if ($e instanceof ValidationException
                || $e instanceof HttpExceptionInterface
                || $e instanceof AuthenticationException
            ) {
                return null;
            }

            if (config('app.debug')) {
                return null;
            }

            return response()->json(['message' => 'Server error.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
