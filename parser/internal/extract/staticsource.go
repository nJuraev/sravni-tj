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

// StaticSourceExtractor — «Markdown страницы → несколько отдельных продуктов»
// для источников kind='static_source' (bank_parse_instructions): URL уже
// точно известен (страница НЕ каталог со ссылками — обычный discovery тут
// не нужен), но на ней целиком показаны НЕСКОЛЬКО РАЗНЫХ продуктов (не
// тарифная сетка одного продукта). Собственный лёгкий промпт/схема БЕЗ
// catalog-режима вообще устраняет саму возможность той путаницы, что ловили
// на ипотеке Амонатбанка (AI иногда уходил в product_links вместо products).
//
// Полностью отдельный от AIExtractor/Extract/systemPrompt/responseSchema() —
// обычный путь парсинга (bank_source_urls БЕЗ extract_mode) не меняется.
type StaticSourceExtractor interface {
	// ExtractStaticProducts извлекает все продукты страницы. notes —
	// постраничная инструкция из bank_source_urls.notes (что именно на этой
	// странице и как отличать продукты друг от друга).
	ExtractStaticProducts(ctx context.Context, markdown string, category model.Category, notes string) (*Extraction, error)
}

// NewStaticSource возвращает лёгкий экстрактор. Поддержаны OpenRouter и
// DeepSeek — тот же прецедент, что у NewRates/NewLinks.
func NewStaticSource(cfg *config.Config, client *http.Client) (StaticSourceExtractor, error) {
	switch cfg.AIProvider {
	case config.AIOpenRouter:
		model := cfg.AIModel
		if model == "" {
			model = defaultOpenRouterModel
		}
		return &openRouterStaticSource{apiKey: cfg.AIAPIKey, model: model, client: client}, nil
	case config.AIDeepSeek:
		return NewDeepSeek(cfg.AIAPIKey, cfg.AIModel, cfg.MaxTokens, client), nil
	default:
		return nil, fmt.Errorf("extract: static-source-экстрактор не поддерживает провайдера %q", cfg.AIProvider)
	}
}

// staticSourceSystemPrompt — те же правила извлечения одного продукта
// (commonExtractionRules, см. extract.go), но БЕЗ раздела catalog-режима:
// для этих источников заранее известно, что страница держит несколько
// продуктов целиком, ссылок искать не нужно, поэтому саму возможность
// вернуть "product_links" из схемы исключили (см. staticSourceSchema).
const staticSourceSystemPrompt = `Ты — система извлечения данных о банковских продуктах Таджикистана.
На этой странице ЗАВЕДОМО НЕСКОЛЬКО отдельных продуктов (не тарифная сетка одного продукта, не каталог со ссылками на подстраницы — все условия уже здесь) — верни КАЖДЫЙ как отдельный объект в "products". Обрати внимание на постраничную подсказку в начале пользовательского сообщения — там сказано, чем конкретно отличаются продукты друг от друга на этой странице.

Верни СТРОГО JSON вида {"products": [...]}. Никакого текста вне JSON.

Жёсткие правила:
` + commonExtractionRules + `
- Даже если у карточек есть свои ссылки на подстраницы (кнопки «Оформить»/«Подробнее» и т.п.) — их ИГНОРИРУЙ, "products" заполняется по условиям, которые уже видны на этой странице.`

func staticSourceUserPrompt(markdown string, category model.Category, notes string) string {
	var b strings.Builder
	if n := strings.TrimSpace(notes); n != "" {
		b.WriteString("Подсказка по этой странице: " + n + "\n\n")
	}
	fmt.Fprintf(&b, "Категория продуктов на этой странице: %s.\n\nMarkdown страницы:\n%s", category, markdown)
	return b.String()
}

var staticSourceSchemaText = schemaPromptText(staticSourceSchema())

// openRouterStaticSource — реализация StaticSourceExtractor через OpenRouter.
type openRouterStaticSource struct {
	apiKey string
	model  string
	client *http.Client
}

func (o *openRouterStaticSource) ExtractStaticProducts(ctx context.Context, markdown string, category model.Category, notes string) (*Extraction, error) {
	reqBody := openAIRequest{
		Model: o.model,
		Messages: []openAIMessage{
			{Role: "system", Content: staticSourceSystemPrompt},
			{Role: "user", Content: staticSourceUserPrompt(markdown, category, notes)},
		},
		Temperature: 0,
		MaxTokens:   8000,
		ResponseFormat: responseFormat{
			Type: "json_schema",
			JSONSchema: jsonSchemaSpec{
				Name:   "static_source_products",
				Strict: true,
				Schema: staticSourceSchema(),
			},
		},
	}
	body, err := json.Marshal(reqBody)
	if err != nil {
		return nil, fmt.Errorf("static_source: marshal: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, openRouterEndpoint, bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("static_source: new request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+o.apiKey)
	req.Header.Set("X-Title", "Sravni.tj static source parser")

	resp, err := o.client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("static_source: do: %w", err)
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
		return nil, fmt.Errorf("static_source: unmarshal envelope: %w", err)
	}
	if parsed.Error != nil {
		return nil, fmt.Errorf("static_source: api error: %s", parsed.Error.Message)
	}
	if len(parsed.Choices) == 0 {
		return nil, fmt.Errorf("static_source: пустой ответ (нет choices)")
	}

	rawText := parsed.Choices[0].Message.Content
	result, err := decodeExtraction(rawText)
	if err != nil {
		return &Extraction{RawResponse: rawText}, fmt.Errorf("static_source: %w", err)
	}
	return &Extraction{Result: result, RawResponse: rawText}, nil
}
