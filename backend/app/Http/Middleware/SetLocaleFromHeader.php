<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Устанавливает локаль приложения по заголовку Accept-Language.
 *
 * Контракт (docs/api/contracts.md): Accept-Language: ru | tg влияет на язык
 * сообщений об ошибках валидации; дефолт — ru. Сами данные продуктов/банков
 * отдаются с ОБОИМИ языками — выбор делает фронт (локаль их не скрывает).
 */
class SetLocaleFromHeader
{
    private const SUPPORTED = ['ru', 'tg'];
    private const DEFAULT = 'ru';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->getPreferredLanguage(self::SUPPORTED) ?? self::DEFAULT;

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = self::DEFAULT;
        }

        App::setLocale($locale);

        return $next($request);
    }
}
