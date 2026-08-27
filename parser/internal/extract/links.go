package extract

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"

	"sravni/parser/internal/config"
	"sravni/parser/internal/model"
)

// CategorizedLink — ссылка на страницу продукта, размеченная категорией
// (для банков, где credit- и deposit-discovery смотрят на один и тот же
// start_url — см. LinksExtractor).
type CategorizedLink struct {
	URL      string         `json:"url"`
	Category model.Category `json:"category"`
	Section  *string        `json:"section"`
}

// LinksResult — обёртка ответа AI для объединённого discovery-запроса.
type LinksResult struct {
	Links []CategorizedLink `json:"links"`
}

// LinksExtraction — результат + сырой ответ модели (для отладки).
type LinksExtraction struct {
	Result      LinksResult
	RawResponse string
}

// LinksExtractor — «Markdown страницы → ссылки на продукты нескольких
// категорий за ОДИН вызов». Нужен банкам, у которых credit- и
// deposit-discovery инструкции смотрят на один и тот же start_url (шапка
// сайта содержит меню обеих категорий сразу) — без него discover.go скрейпил
// бы и звал AI на этой же странице дважды с идентичным текстом.
//
// Полностью отдельный от AIExtractor/Extract/systemPrompt/responseSchema() —
// обычный парсинг продуктов (parser/internal/parser) эту абстракцию не
// использует и не меняется вообще.
type LinksExtractor interface {
	// ExtractLinks ищет ссылки для набора категорий за один вызов. hints —
	// подсказка (menu_sections+notes) для каждой категории из group,
	// см. discover.go buildLinksHint.
	ExtractLinks(ctx context.Context, markdown string, categories []model.Category, hints map[model.Category]string) (*LinksExtraction, error)
}

// NewLinks возвращает экстрактор ссылок. Поддержаны OpenRouter и DeepSeek —
// тот же прецедент, что у NewRates (см. rates.go): для остальных провайдеров
// явная ошибка, добавляется по мере надобности.
func NewLinks(cfg *config.Config, client *http.Client) (LinksExtractor, error) {
	switch cfg.AIProvider {
	case config.AIOpenRouter:
		model := cfg.AIModel
		if model == "" {
			model = defaultOpenRouterModel
		}
		return &openRouterLinks{apiKey: cfg.AIAPIKey, model: model, client: client}, nil
	case config.AIDeepSeek:
		return NewDeepSeek(cfg.AIAPIKey, cfg.AIModel, cfg.MaxTokens, client), nil
	default:
		return nil, fmt.Errorf("extract: links-экстрактор не поддерживает провайдера %q", cfg.AIProvider)
	}
}

// linksSystemPrompt — повторяет анти-галлюцинационное правило общего
// systemPrompt (extract.go), адаптированное под разметку по категориям
// вместо одной подразумеваемой.
const linksSystemPrompt = `Ты — система поиска ссылок на страницы банковских продуктов Таджикистана.
Тебе дан Markdown страницы и список категорий продуктов с подсказкой по каждой (разделы меню/примечания).
Верни СТРОГО JSON вида {"links": [...]}. Никакого текста вне JSON.

Жёсткие правила:
- КАЖДЫЙ url ОБЯЗАН дословно встречаться в тексте как markdown-ссылка "[текст](url)". Категорически ЗАПРЕЩЕНО достраивать/придумывать URL по аналогии — если ссылки в тексте нет, её не должно быть в ответе. Нарушение — то же самое, что выдумывание данных.
- Каждую найденную ссылку отнеси РОВНО к одной категории из заданного списка, используя её раздел меню и подсказку по категории; если ссылка не относится ни к одной из заданных категорий (юрлица, карты, переводы, новости, реклама, документы) — не включай её вообще.
- Если для какой-то категории на этой же странице уже есть ПОЛНЫЕ условия продукта (ставка/сумма/срок), а не только ссылки на другие страницы — верни для неё пустой список ссылок (эта страница будет разобрана отдельно как страница самого продукта).
- section — заголовок раздела меню, из которого взята ссылка, или null.
- Только продукты для физических лиц, без повторов, без внешних доменов.`

func linksUserPrompt(markdown string, categories []model.Category, hints map[model.Category]string) string {
	var b strings.Builder
	b.WriteString("Категории и подсказки:\n")
	for _, cat := range categories {
		fmt.Fprintf(&b, "- %q: %s\n", cat, strings.TrimSpace(hints[cat]))
	}
	b.WriteString("\nMarkdown страницы:\n")
	b.WriteString(markdown)
	return b.String()
}

// linksSchema — JSON Schema ответа для strict json_schema режима.
func linksSchema() map[string]any {
	linkItem := map[string]any{
		"type":                 "object",
		"additionalProperties": false,
		"required":             []string{"url", "category", "section"},
		"properties": map[string]any{
			"url":      map[string]any{"type": "string"},
			"category": map[string]any{"type": "string", "enum": []string{"credit", "deposit", "installment"}},
			"section":  map[string]any{"type": []string{"string", "null"}},
		},
	}
	return map[string]any{
		"type":                 "object",
		"additionalProperties": false,
		"required":             []string{"links"},
		"properties": map[string]any{
			"links": map[string]any{
				"type":        "array",
				"maxItems":    120,
				"description": "Ссылки на страницы продуктов, размеченные категорией. Пусто для категории — её страница уже содержит полные условия.",
				"items":       linkItem,
			},
		},
	}
}

// decodeLinks строго разбирает JSON-ответ модели.
func decodeLinks(raw string) (LinksResult, error) {
	cleaned := stripCodeFence(raw)
	var res LinksResult
	dec := json.NewDecoder(strings.NewReader(cleaned))
	if err := dec.Decode(&res); err != nil {
		return LinksResult{}, fmt.Errorf("decode links: %w", err)
	}
	return res, nil
}

// openRouterLinks — реализация LinksExtractor через OpenRouter.
type openRouterLinks struct {
	apiKey string
	model  string
	client *http.Client
}

func (o *openRouterLinks) ExtractLinks(ctx context.Context, markdown string, categories []model.Category, hints map[model.Category]string) (*LinksExtraction, error) {
	reqBody := openAIRequest{
		Model: o.model,
		Messages: []openAIMessage{
			{Role: "system", Content: linksSystemPrompt},
			{Role: "user", Content: linksUserPrompt(markdown, categories, hints)},
		},
		Temperature: 0,
		MaxTokens:   4000,
		ResponseFormat: responseFormat{
			Type: "json_schema",
			JSONSchema: jsonSchemaSpec{
				Name:   "product_links",
				Strict: true,
				Schema: linksSchema(),
			},
		},
	}
	body, err := json.Marshal(reqBody)
	if err != nil {
		return nil, fmt.Errorf("links: marshal: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, openRouterEndpoint, bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("links: new request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+o.apiKey)
	req.Header.Set("X-Title", "Sravni.tj links discovery")

	resp, err := o.client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("links: do: %w", err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, &APIError{
			StatusCode: resp.StatusCode,
			RetryAfter: resp.Header.Get("Retry-After"),
			Body:       truncateRunes(string(raw), 500),
		}
	}

	var parsed openAIResponse
	if err := json.Unmarshal(raw, &parsed); err != nil {
		return nil, fmt.Errorf("links: unmarshal envelope: %w", err)
	}
	if parsed.Error != nil {
		return nil, fmt.Errorf("links: api error: %s", parsed.Error.Message)
	}
	if len(parsed.Choices) == 0 {
		return nil, fmt.Errorf("links: пустой ответ (нет choices)")
	}

	rawText := parsed.Choices[0].Message.Content
	result, err := decodeLinks(rawText)
	if err != nil {
		return &LinksExtraction{RawResponse: rawText}, fmt.Errorf("links: %w", err)
	}
	return &LinksExtraction{Result: result, RawResponse: rawText}, nil
}
