// Package scrape отвечает за этап 1 пайплайна: загрузку страницы банка и
// конвертацию HTML→Markdown (specs/parser.md §3, этап 1).
//
// Интерфейс Scraper позволяет мокать скрейпинг в тестах и менять провайдера
// (Firecrawl ↔ Jina) без изменения оркестратора.
package scrape

import (
	"context"
	"fmt"
	"net/http"

	"sravni/parser/internal/config"
)

// Scraper — абстракция «URL → Markdown».
type Scraper interface {
	// Scrape загружает url и возвращает Markdown-представление страницы
	// (readability — только основной контент). Ошибка при сети/таймауте/не-2xx/пустом.
	Scrape(ctx context.Context, url string) (string, error)

	// ScrapeRaw возвращает СЫРОЙ HTML страницы (включая <script>), нужен для
	// SPA-сайтов, где данные (курсы) лежат в JSON внутри скриптов, а readability их режет.
	ScrapeRaw(ctx context.Context, url string) (string, error)
}

// HTTPError — не-2xx ответ внешнего сервиса. Несёт RetryAfter (из заголовка
// Retry-After при 429), чтобы оркестратор уважал rate-limit (§7.2).
type HTTPError struct {
	StatusCode int
	RetryAfter string // сырое значение заголовка Retry-After, если был
	Body       string // усечённое тело для диагностики (без секретов)
}

func (e *HTTPError) Error() string {
	return fmt.Sprintf("scrape: HTTP %d: %s", e.StatusCode, e.Body)
}

// New возвращает реализацию Scraper по конфигу.
func New(cfg *config.Config, client *http.Client) (Scraper, error) {
	switch cfg.ScraperProvider {
	case config.ScraperFirecrawl:
		return NewFirecrawl(cfg.ScraperAPIKey, client), nil
	case config.ScraperJina:
		return NewJina(cfg.ScraperAPIKey, client), nil
	default:
		return nil, fmt.Errorf("scrape: неизвестный провайдер %q", cfg.ScraperProvider)
	}
}
