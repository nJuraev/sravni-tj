<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверяет Bearer-токен внешней публикации статей (POST /api/articles/publish)
 * против ARTICLE_PUBLISH_TOKEN (hash_equals, не === — этот эндпоинт пишет
 * публичный контент напрямую без модерации, выше цена timing-атаки) и, ТОЛЬКО
 * если токен верный, считает его в общий лимит 3/час (ключ константный, не по
 * IP — токен один на всех вызывающих, лимит общий).
 *
 * Лимит и аутентификация сделаны одной middleware намеренно, а не
 * `->middleware(['article.publish.token', 'throttle:...'])`: встроенный
 * `Illuminate\Foundation\Http\Kernel::$middlewarePriority` всегда прогоняет
 * `ThrottleRequests` раньше кастомной middleware, независимо от порядка в
 * массиве роута — неверный токен тогда всё равно жрал бы квоту. Проверено
 * на практике (ArticlePublishTest), не гипотеза.
 */
class VerifyArticlePublishToken
{
    private const RATE_LIMIT_KEY = 'article-publish';
    private const MAX_ATTEMPTS_PER_HOUR = 3;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.article_publish.token');
        $received = (string) $request->bearerToken();

        if ($expected === '' || $received === '' || ! hash_equals($expected, $received)) {
            abort(401);
        }

        if ($this->limiter->tooManyAttempts(self::RATE_LIMIT_KEY, self::MAX_ATTEMPTS_PER_HOUR)) {
            abort(429, 'Too many publish attempts, try again later.');
        }

        $this->limiter->hit(self::RATE_LIMIT_KEY, 3600);

        return $next($request);
    }
}
