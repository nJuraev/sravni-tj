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

// processDeterministic — путь без AI и без Jina/Firecrawl: прямой GET на
// in.StartURL (JSON-эндпоинт), значения читаются по путям in.RateRule.Items
// (см. jsonpath.Resolve). Формат отличный от "json_path" — падаем на AI-путь
// (см. pg.go: RateRule собирается только при Format != "").
func (r *Rater) processDeterministic(ctx context.Context, in model.DiscoveryInstruction, startedAt time.Time) {
	if in.RateRule.Format != "json_path" {
		r.log.Warn("rates: неизвестный формат rate_rule, пропуск", "instruction_id", in.ID, "format", in.RateRule.Format)
		return
	}

	data, err := fetchJSON(ctx, r.httpClient, in.StartURL)
	if err != nil {
		r.log.Warn("rates: GET rate_rule не удался", "instruction_id", in.ID, "url", in.StartURL, "err", err)
		return
	}

	rows := make([]model.RateRow, 0, len(in.RateRule.Items))
	for _, item := range in.RateRule.Items {
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
