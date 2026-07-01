package scrape

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
)

// firecrawlEndpoint — v1 scrape API. Возвращает Markdown в data.markdown.
const firecrawlEndpoint = "https://api.firecrawl.dev/v1/scrape"

// Firecrawl — реализация Scraper поверх Firecrawl API.
type Firecrawl struct {
	apiKey string
	client *http.Client
}

// NewFirecrawl создаёт скрейпер Firecrawl.
func NewFirecrawl(apiKey string, client *http.Client) *Firecrawl {
	return &Firecrawl{apiKey: apiKey, client: client}
}

// firecrawlRequest — тело запроса: просим только markdown-формат.
type firecrawlRequest struct {
	URL     string   `json:"url"`
	Formats []string `json:"formats"`
}

// firecrawlResponse — ответ Firecrawl v1.
type firecrawlResponse struct {
	Success bool `json:"success"`
	Data    struct {
		Markdown string `json:"markdown"`
	} `json:"data"`
	Error string `json:"error"`
}

// Scrape реализует Scraper.
func (f *Firecrawl) Scrape(ctx context.Context, url string) (string, error) {
	body, err := json.Marshal(firecrawlRequest{URL: url, Formats: []string{"markdown"}})
	if err != nil {
		return "", fmt.Errorf("firecrawl: marshal: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, firecrawlEndpoint, bytes.NewReader(body))
	if err != nil {
		return "", fmt.Errorf("firecrawl: new request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+f.apiKey)

	resp, err := f.client.Do(req)
	if err != nil {
		// Транзиентная сетевая ошибка/таймаут — оркестратор ретраит.
		return "", fmt.Errorf("firecrawl: do: %w", err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20)) // защита от гигантских тел
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", &HTTPError{
			StatusCode: resp.StatusCode,
			RetryAfter: resp.Header.Get("Retry-After"),
			Body:       truncate(string(raw), 500),
		}
	}

	var parsed firecrawlResponse
	if err := json.Unmarshal(raw, &parsed); err != nil {
		return "", fmt.Errorf("firecrawl: unmarshal: %w", err)
	}
	if !parsed.Success {
		return "", fmt.Errorf("firecrawl: success=false: %s", parsed.Error)
	}

	md := strings.TrimSpace(parsed.Data.Markdown)
	if md == "" {
		return "", fmt.Errorf("firecrawl: пустой markdown")
	}
	return md, nil
}

// ScrapeRaw: Firecrawl сейчас не используется (основной провайдер — Jina).
// Заглушка для интерфейса: возвращает markdown (без сохранения <script>).
// При необходимости заменить на formats:["rawHtml"] и поле data.rawHtml.
func (f *Firecrawl) ScrapeRaw(ctx context.Context, url string) (string, error) {
	return f.Scrape(ctx, url)
}

// truncate усекает строку до n рун для безопасного логирования.
func truncate(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n]) + "…"
}
