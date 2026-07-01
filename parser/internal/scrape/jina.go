package scrape

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"strings"
)

// jinaPrefix — Jina Reader: GET https://r.jina.ai/<url> возвращает Markdown.
const jinaPrefix = "https://r.jina.ai/"

// Jina — реализация Scraper поверх Jina Reader API.
type Jina struct {
	apiKey string
	client *http.Client
}

// NewJina создаёт скрейпер Jina.
func NewJina(apiKey string, client *http.Client) *Jina {
	return &Jina{apiKey: apiKey, client: client}
}

// Scrape реализует Scraper. Jina Reader отдаёт готовый Markdown текстом.
func (j *Jina) Scrape(ctx context.Context, url string) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, jinaPrefix+url, nil)
	if err != nil {
		return "", fmt.Errorf("jina: new request: %w", err)
	}
	// Ключ опционален для Jina (есть бесплатный режим), но при наличии повышает лимиты.
	if j.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+j.apiKey)
	}
	// БЕЗ X-Return-Format: дефолтный режим Jina = readability (только основной
	// контент). Заголовок "markdown" возвращал ВЕСЬ DOM (шапка/меню/футер/глобальная
	// таблица курсов) → ~3× лишних токенов и шум для AI. Readability чище и дешевле.
	req.Header.Set("Accept", "text/plain")

	resp, err := j.client.Do(req)
	if err != nil {
		return "", fmt.Errorf("jina: do: %w", err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", &HTTPError{
			StatusCode: resp.StatusCode,
			RetryAfter: resp.Header.Get("Retry-After"),
			Body:       truncate(string(raw), 500),
		}
	}

	md := strings.TrimSpace(string(raw))
	if md == "" {
		return "", fmt.Errorf("jina: пустой markdown")
	}
	return md, nil
}

// ScrapeRaw возвращает сырой HTML (X-Return-Format: html), сохраняя <script>.
// Нужен для SPA, где данные (курсы) лежат в JSON внутри скриптов.
func (j *Jina) ScrapeRaw(ctx context.Context, url string) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, jinaPrefix+url, nil)
	if err != nil {
		return "", fmt.Errorf("jina: new request: %w", err)
	}
	if j.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+j.apiKey)
	}
	req.Header.Set("X-Return-Format", "html")

	resp, err := j.client.Do(req)
	if err != nil {
		return "", fmt.Errorf("jina: do: %w", err)
	}
	defer resp.Body.Close()

	// Сырой HTML крупнее markdown — поднимаем лимит чтения до 8 МБ.
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", &HTTPError{
			StatusCode: resp.StatusCode,
			RetryAfter: resp.Header.Get("Retry-After"),
			Body:       truncate(string(raw), 500),
		}
	}

	html := strings.TrimSpace(string(raw))
	if html == "" {
		return "", fmt.Errorf("jina: пустой html")
	}
	return html, nil
}
