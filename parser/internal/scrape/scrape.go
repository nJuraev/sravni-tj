// Package scrape отвечает за этап 1 пайплайна: загрузку страницы банка и
// конвертацию HTML→текст (specs/parser.md §3, этап 1).
//
// Три скрейпера, выбор — ПЕР-ИСТОЧНИК (bank_source_urls.scraper /
// bank_parse_instructions.scraper), не глобальный env:
//   - Direct (свой) — прямой HTTP GET, бесплатно, без внешних сервисов;
//     дефолт для server-rendered страниц (большинство банков).
//   - Browser (свой) — headless Chrome по CDP (свой контейнер), полноценный
//     JS-рендер без внешнего платного сервиса. Основной выбор там, где Direct
//     не справляется (client-rendered SPA, anti-bot).
//   - Firecrawl — платный внешний сервис, тот же класс задач, что и Browser;
//     оставлен как ручной фолбэк (Browser может не проходить конкретную
//     anti-bot защиту, которую проходит Firecrawl, и наоборот).
package scrape

import (
	"context"
	"fmt"
	"net/http"

	"sravni/parser/internal/config"
)

// Scraper — абстракция «URL → текст».
type Scraper interface {
	// Scrape загружает url и возвращает текстовое представление страницы
	// (readability — только основной контент). Ошибка при сети/таймауте/не-2xx/пустом.
	Scrape(ctx context.Context, url string) (string, error)

	// ScrapeRaw возвращает СЫРОЙ HTML страницы (включая <script>), нужен для
	// SPA-сайтов, где данные (курсы) лежат в JSON внутри скриптов, а readability их режет.
	ScrapeRaw(ctx context.Context, url string) (string, error)
}

// ModeFirecrawl — значение bank_source_urls.scraper / bank_parse_instructions.scraper,
// требующее рендер-скрейпер вместо своего. Пусто ("") — свой (Direct).
const ModeFirecrawl = "firecrawl"

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

// Scrapers — все скрейперы сразу; оркестраторы выбирают через For(mode) по
// значению колонки конкретного источника.
type Scrapers struct {
	Own       Scraper
	Browser   Scraper
	Firecrawl Scraper
}

// For возвращает скрейпер по значению колонки scraper ("" — свой Direct,
// см. ModeBrowser/ModeFirecrawl).
func (s *Scrapers) For(mode string) Scraper {
	switch mode {
	case ModeBrowser:
		return s.Browser
	case ModeFirecrawl:
		return s.Firecrawl
	default:
		return s.Own
	}
}

// New создаёт все скрейперы. BrowserCDPURL/FirecrawlAPIKey могут быть
// пустыми, если в БД ни один источник не помечен соответствующим scraper.
func New(cfg *config.Config, client *http.Client) (*Scrapers, error) {
	return &Scrapers{
		Own:       NewDirect(client),
		Browser:   NewBrowser(cfg.BrowserCDPURL, client),
		Firecrawl: NewFirecrawl(cfg.ScraperAPIKey, client),
	}, nil
}
