package scrape

import (
	"context"
	"fmt"
	"html"
	"io"
	"net/http"
	"regexp"
	"strings"
)

// Direct — свой скрейпер: прямой HTTP GET, без внешних сервисов и без
// оплаты/лимитов. Годится для server-rendered страниц (большинство банков) —
// не умеет рендерить JS и не проходит anti-bot защиты (для таких источников
// в БД выставляется scraper='firecrawl', см. Scrapers.For).
type Direct struct {
	client *http.Client
}

// NewDirect создаёт свой скрейпер.
func NewDirect(client *http.Client) *Direct {
	return &Direct{client: client}
}

// Scrape реализует Scraper: GET + readability-lite (вырезать script/style,
// снять теги, схлопнуть пробелы) — грубый аналог Jina readability-режима,
// достаточный для AI-экстрактора (ему нужен текст, не разметка).
func (d *Direct) Scrape(ctx context.Context, url string) (string, error) {
	raw, err := d.get(ctx, url, 4<<20)
	if err != nil {
		return "", err
	}
	text := strings.TrimSpace(htmlToText(raw))
	if text == "" {
		return "", fmt.Errorf("direct: пустой текст после очистки")
	}
	return text, nil
}

// ScrapeRaw возвращает сырой HTML как есть (без выполнения JS) — нужен там,
// где данные лежат в JSON внутри <script> статически (без клиентского рендера).
func (d *Direct) ScrapeRaw(ctx context.Context, url string) (string, error) {
	raw, err := d.get(ctx, url, 8<<20)
	if err != nil {
		return "", err
	}
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return "", fmt.Errorf("direct: пустой html")
	}
	return raw, nil
}

func (d *Direct) get(ctx context.Context, rawURL string, limit int64) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, rawURL, nil)
	if err != nil {
		return "", fmt.Errorf("direct: new request: %w", err)
	}
	// UA "браузерный" — часть банковских сайтов режет ответ ботам без него.
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36")
	req.Header.Set("Accept", "text/html,application/xhtml+xml")

	resp, err := d.client.Do(req)
	if err != nil {
		return "", fmt.Errorf("direct: do: %w", err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(resp.Body, limit))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", &HTTPError{
			StatusCode: resp.StatusCode,
			RetryAfter: resp.Header.Get("Retry-After"),
			Body:       truncate(string(raw), 500),
		}
	}
	return string(raw), nil
}

var (
	// reCut вырезает целиком script/style/noscript/svg-блоки (включая
	// содержимое — там нет читаемого текста, только код/CSS/иконки).
	reCut        = regexp.MustCompile(`(?is)<(script|style|noscript|svg)[^>]*>.*?</(script|style|noscript|svg)>`)
	reComment    = regexp.MustCompile(`(?s)<!--.*?-->`)
	// RE2 (Go regexp) не умеет backreferences — кавычки-открывашка/закрывашка
	// проверяются двумя альтернативами, а не \1.
	reAnchor = regexp.MustCompile(`(?is)<a\s+[^>]*?href\s*=\s*(?:"([^"]*)"|'([^']*)')[^>]*>(.*?)</a>`)
	reBlockTag   = regexp.MustCompile(`(?i)</(p|div|section|article|li|tr|h1|h2|h3|h4|h5|h6|br|table|ul|ol)>`)
	reTag        = regexp.MustCompile(`(?s)<[^>]+>`)
	reBlankLines = regexp.MustCompile(`\n{3,}`)
	reSpaces     = regexp.MustCompile(`[ \t]{2,}`)
)

// linkify заменяет <a href="URL">текст</a> на markdown-ссылку [текст](URL)
// ДО общей зачистки тегов ниже. Критично для discovery (internal/discover):
// без этого href пропадает вместе с тегом целиком, и AI, видя только голый
// текст пункта меню без единой ссылки, вынужден УГАДЫВАТЬ URL по паттерну
// имени — поймано вживую на eskhata.com: 7 "найденных" ссылок на кредиты
// оказались несуществующими (404), AI их придумал. Относительные href не
// резолвим — resolveAndFilter в discover.go уже это делает.
func linkify(s string) string {
	return reAnchor.ReplaceAllStringFunc(s, func(m string) string {
		sub := reAnchor.FindStringSubmatch(m)
		href := sub[1]
		if href == "" {
			href = sub[2]
		}
		href = strings.TrimSpace(href)
		text := strings.TrimSpace(reSpaces.ReplaceAllString(reTag.ReplaceAllString(sub[3], " "), " "))
		if href == "" || text == "" || strings.HasPrefix(href, "#") || strings.HasPrefix(href, "javascript:") {
			return " "
		}
		return " [" + text + "](" + href + ") "
	})
}

// htmlToText — readability-lite без внешних зависимостей: не ищет "основной
// контент" по DOM-скорингу (как Jina/Readability), просто снимает разметку и
// шум (плюс сохраняет ссылки как markdown, см. linkify). Хуже режет шапку/
// меню/футер, чем настоящий readability, но AI-экстрактор и так игнорирует
// нерелевантный текст — здесь важно лишь не терять данные.
func htmlToText(raw string) string {
	s := reCut.ReplaceAllString(raw, "\n")
	s = reComment.ReplaceAllString(s, "")
	s = linkify(s)
	s = reBlockTag.ReplaceAllString(s, "\n")
	s = reTag.ReplaceAllString(s, " ")
	s = html.UnescapeString(s)
	s = reSpaces.ReplaceAllString(s, " ")
	lines := strings.Split(s, "\n")
	out := make([]string, 0, len(lines))
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line != "" {
			out = append(out, line)
		}
	}
	return reBlankLines.ReplaceAllString(strings.Join(out, "\n"), "\n\n")
}
