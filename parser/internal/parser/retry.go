package parser

import (
	"context"
	"errors"
	"os"
	"strconv"
	"time"

	"sravni/parser/internal/extract"
	"sravni/parser/internal/scrape"
)

// maxAttempts — максимум попыток на одну транзиентную операцию (§7.2).
// Переопределяется PARSER_MAX_ATTEMPTS (>=1); =1 полностью отключает ретраи.
var maxAttempts = func() int {
	if v := os.Getenv("PARSER_MAX_ATTEMPTS"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n >= 1 {
			return n
		}
	}
	return 3
}()

// baseBackoff — базовая задержка экспоненциального backoff (1s → 2s → 4s).
const baseBackoff = time.Second

// retry выполняет fn с экспоненциальным backoff для транзиентных ошибок.
//
// Решение о ретрае принимает retryable(err). Если ошибка несёт Retry-After
// (HTTP 429), задержка не короче указанной (§7.2). Контекст уважается.
func retry[T any](ctx context.Context, fn func() (T, error)) (T, error) {
	var lastRes T
	var lastErr error

	for attempt := 1; attempt <= maxAttempts; attempt++ {
		res, err := fn()
		if err == nil {
			return res, nil
		}
		// Сохраняем последний результат при ошибке: например, *extract.Extraction
		// несёт RawResponse даже при ошибке декодирования — он нужен для parser_runs.
		lastRes, lastErr = res, err

		// Нетранзиентная ошибка — ретрай не поможет.
		if !retryable(err) {
			return res, err
		}
		// Последняя попытка — не спим, возвращаем ошибку.
		if attempt == maxAttempts {
			break
		}

		delay := backoffDelay(attempt, err)
		select {
		case <-ctx.Done():
			return lastRes, ctx.Err()
		case <-time.After(delay):
		}
	}
	return lastRes, lastErr
}

// retryable определяет, транзиентна ли ошибка.
// Сетевые ошибки/таймауты/5xx/429 — да; 4xx (кроме 429) — нет (детерминированно).
func retryable(err error) bool {
	var scrapeHTTP *scrape.HTTPError
	if errors.As(err, &scrapeHTTP) {
		return isRetryableStatus(scrapeHTTP.StatusCode)
	}
	var aiHTTP *extract.APIError
	if errors.As(err, &aiHTTP) {
		return isRetryableStatus(aiHTTP.StatusCode)
	}
	// Прочие ошибки (сеть/таймаут/decode) считаем транзиентными — кроме контекста.
	if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
		// DeadlineExceeded на уровне per-call таймаута — транзиентен (ретраим),
		// но если это отмена всего прогона — ретрай всё равно упрётся в ctx.Done.
		return !errors.Is(err, context.Canceled)
	}
	return true
}

// isRetryableStatus: 429 и 5xx ретраятся, прочие 4xx — нет.
func isRetryableStatus(code int) bool {
	if code == 429 {
		return true
	}
	return code >= 500 && code < 600
}

// backoffDelay вычисляет задержку перед попыткой attempt (1-based).
// Уважает Retry-After из 429-ошибки, если он задан и больше расчётного backoff.
func backoffDelay(attempt int, err error) time.Duration {
	delay := baseBackoff << (attempt - 1) // 1s, 2s, 4s, ...

	if ra := retryAfterOf(err); ra > 0 && ra > delay {
		delay = ra
	}
	return delay
}

// retryAfterOf извлекает Retry-After (в секундах) из ошибки скрейпера/AI.
func retryAfterOf(err error) time.Duration {
	var raw string
	var scrapeHTTP *scrape.HTTPError
	var aiHTTP *extract.APIError
	switch {
	case errors.As(err, &scrapeHTTP):
		raw = scrapeHTTP.RetryAfter
	case errors.As(err, &aiHTTP):
		raw = aiHTTP.RetryAfter
	}
	if raw == "" {
		return 0
	}
	if secs, e := strconv.Atoi(raw); e == nil && secs > 0 {
		return time.Duration(secs) * time.Second
	}
	return 0
}
