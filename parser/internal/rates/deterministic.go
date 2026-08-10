package rates

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"

	"sravni/parser/internal/jsonpath"
	"sravni/parser/internal/model"
)

// processDeterministic — путь без AI и без Jina/Firecrawl: прямой GET,
// значения читаются по путям in.RateRule.Items (см. jsonpath.Resolve).
// Формат "json_path" — тело ответа сразу валидный JSON. Формат "html_json" —
// тело обычная HTML-страница, а нужные данные лежат ВНУТРИ неё как JSON-блок
// (инлайн <script>, Next.js RSC flight-стрим) — вырезается по JSONMarker,
// см. extractJSONBlock/unmarshalMaybeEscaped. Неизвестный формат — падаем на
// AI-путь (см. pg.go: RateRule собирается только при Format != "").
func (r *Rater) processDeterministic(ctx context.Context, in model.DiscoveryInstruction, startedAt time.Time) {
	rr := in.RateRule

	var fetchOne func(url string) (any, error)
	switch rr.Format {
	case "json_path":
		fetchOne = func(url string) (any, error) { return fetchJSON(ctx, r.httpClient, url) }
	case "html_json":
		if rr.JSONMarker == "" {
			r.log.Warn("rates: html_json без json_marker, пропуск", "instruction_id", in.ID)
			return
		}
		fetchOne = func(url string) (any, error) { return fetchHTMLJSON(ctx, r.httpClient, url, rr.JSONMarker) }
	default:
		r.log.Warn("rates: неизвестный формат rate_rule, пропуск", "instruction_id", in.ID, "format", rr.Format)
		return
	}

	// Кэш по URL: большинство items читают один и тот же ответ (in.StartURL),
	// но item.URL позволяет части items читать СВОЙ URL — банк может отдавать
	// разные категории на разных эндпоинтах/query-параметрах, а строка
	// instruction (kind=rates) у банка одна (chk_bpi_category: category IS NULL).
	cache := map[string]any{}
	fetch := func(url string) (any, error) {
		if data, ok := cache[url]; ok {
			return data, nil
		}
		data, err := fetchOne(url)
		if err != nil {
			return nil, err
		}
		cache[url] = data
		return data, nil
	}

	rows := make([]model.RateRow, 0, len(rr.Items))
	for _, item := range rr.Items {
		url := item.URL
		if url == "" {
			url = in.StartURL
		}
		data, err := fetch(url)
		if err != nil {
			r.log.Warn("rates: GET rate_rule не удался", "instruction_id", in.ID, "url", url, "err", err)
			continue
		}

		row := model.RateRow{Currency: item.Currency, Category: item.Category}
		if item.BuyPath != "" {
			if v, ok := resolveFloat(data, item.BuyPath); ok {
				row.Buy = &v
			}
		}
		if item.SellPath != "" {
			if v, ok := resolveFloat(data, item.SellPath); ok {
				row.Sell = &v
			}
		}
		rows = append(rows, row)
	}

	r.saveRates(ctx, in, startedAt, rows)
}

// fetchJSON делает прямой GET и декодирует тело как произвольный JSON
// (объект ИЛИ массив на верхнем уровне).
func fetchJSON(ctx context.Context, client *http.Client, url string) (any, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, fmt.Errorf("rates: new request: %w", err)
	}
	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("rates: do: %w", err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("rates: HTTP %d: %s", resp.StatusCode, truncateRunes(string(raw), 300))
	}

	var data any
	if err := json.Unmarshal(raw, &data); err != nil {
		return nil, fmt.Errorf("rates: unmarshal JSON: %w", err)
	}
	return data, nil
}

// fetchHTMLJSON делает GET страницы (HTML, не JSON) и вырезает из тела JSON-
// блок, начинающийся сразу за jsonMarker (см. extractJSONBlock), затем
// разбирает его (см. unmarshalMaybeEscaped — блок бывает escaped-строкой
// внутри JSON, как в Next.js RSC flight-стриме).
func fetchHTMLJSON(ctx context.Context, client *http.Client, url, jsonMarker string) (any, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, fmt.Errorf("rates: new request: %w", err)
	}
	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("rates: do: %w", err)
	}
	defer resp.Body.Close()

	// Страница крупнее чистого JSON API — лимит выше, чем у fetchJSON.
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 5<<20))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("rates: HTTP %d: %s", resp.StatusCode, truncateRunes(string(raw), 300))
	}

	block, ok := extractJSONBlock(string(raw), jsonMarker)
	if !ok {
		return nil, fmt.Errorf("rates: html_json: маркер %q не найден или скобки не сбалансированы", jsonMarker)
	}
	return unmarshalMaybeEscaped(block)
}

// extractJSONBlock вырезает сбалансированный JSON-массив/объект: ищет
// jsonMarker, последний символ которого — открывающая скобка ('[' или '{'),
// и считает глубину скобок ДО закрывающей на той же глубине. Без учёта
// кавычек/escape внутри — данные курсов простые (числа, короткие ярлыки
// валют/категорий), случайных непарных [] в значениях не бывает.
func extractJSONBlock(body, jsonMarker string) (string, bool) {
	idx := strings.Index(body, jsonMarker)
	if idx < 0 || jsonMarker == "" {
		return "", false
	}
	start := idx + len(jsonMarker) - 1
	if start < 0 || start >= len(body) {
		return "", false
	}
	open := body[start]
	if open != '[' && open != '{' {
		return "", false
	}
	closeCh := byte(']')
	if open == '{' {
		closeCh = '}'
	}
	depth := 0
	for i := start; i < len(body); i++ {
		switch body[i] {
		case open:
			depth++
		case closeCh:
			depth--
			if depth == 0 {
				return body[start : i+1], true
			}
		}
	}
	return "", false
}

// unmarshalMaybeEscaped разбирает JSON-блок, вырезанный extractJSONBlock.
// Блок бывает ЧИСТЫМ JSON (инлайн <script>JSON</script> в статике) или
// JSON-СТРОКОЙ внутри другого JSON — Next.js RSC flight-стрим сериализует
// вложенный контент как escaped-строку (кавычки внутри — буквально `\"` в
// байтах ответа, см. Хумо). Пробуем прямой разбор; при ошибке — снимаем ОДИН
// уровень escape (оборачиваем в кавычки, разбираем как JSON-строку) и
// разбираем результат уже как настоящий JSON.
func unmarshalMaybeEscaped(block string) (any, error) {
	var data any
	if err := json.Unmarshal([]byte(block), &data); err == nil {
		return data, nil
	}

	var unescaped string
	if err := json.Unmarshal([]byte(`"`+block+`"`), &unescaped); err != nil {
		return nil, fmt.Errorf("rates: html_json: блок не разобрался ни как JSON, ни как escaped-строка: %w", err)
	}
	if err := json.Unmarshal([]byte(unescaped), &data); err != nil {
		return nil, fmt.Errorf("rates: html_json: unmarshal после снятия escape: %w", err)
	}
	return data, nil
}

// resolveFloat резолвит путь (jsonpath.Resolve) и приводит результат к числу.
// Строковые числа ("18.5") принимаются — многие API отдают курсы строками.
func resolveFloat(data any, path string) (float64, bool) {
	v, ok := jsonpath.Resolve(data, path)
	if !ok {
		return 0, false
	}
	switch t := v.(type) {
	case float64:
		return t, true
	case string:
		f, err := strconv.ParseFloat(strings.TrimSpace(t), 64)
		if err != nil {
			return 0, false
		}
		return f, true
	default:
		return 0, false
	}
}

// truncateRunes усекает строку до n рун для безопасного логирования.
func truncateRunes(s string, n int) string {
	rr := []rune(s)
	if len(rr) <= n {
		return s
	}
	return string(rr[:n]) + "…"
}
