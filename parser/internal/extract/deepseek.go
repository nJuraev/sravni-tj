package extract

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"

	"sravni/parser/internal/model"
)

// deepSeekEndpoint — OpenAI-совместимый chat/completions прямого API DeepSeek.
const deepSeekEndpoint = "https://api.deepseek.com/chat/completions"

// defaultDeepSeekModel — модель по умолчанию (переопределяется AI_MODEL).
// DeepSeek прекратил поддержку алиаса "deepseek-chat" — актуальные имена
// моделей: deepseek-v4-pro | deepseek-v4-flash.
const defaultDeepSeekModel = "deepseek-v4-flash"

// DeepSeek — экстрактор через прямой API DeepSeek. В отличие от OpenRouter,
// DeepSeek НЕ поддерживает strict json_schema, только response_format json_object,
// поэтому схема задаётся текстом промпта, а ответ валидируется после декода.
// Реализует и AIExtractor (продукты), и RatesExtractor (курсы).
type DeepSeek struct {
	apiKey    string
	model     string
	maxTokens int
	client    *http.Client
}

// NewDeepSeek создаёт экстрактор DeepSeek. Пустая model → defaultDeepSeekModel,
// maxTokens<=0 → defaultMaxTokens (но не больше 8192 — потолок вывода модели).
func NewDeepSeek(apiKey, modelName string, maxTokens int, client *http.Client) *DeepSeek {
	if modelName == "" {
		modelName = defaultDeepSeekModel
	}
	if maxTokens <= 0 {
		maxTokens = defaultMaxTokens
	}
	if maxTokens > 8192 {
		maxTokens = 8192 // deepseek-chat max output
	}
	return &DeepSeek{apiKey: apiKey, model: modelName, maxTokens: maxTokens, client: client}
}

// dsRequest — тело запроса DeepSeek (json_object вместо json_schema).
type dsRequest struct {
	Model          string            `json:"model"`
	Messages       []openAIMessage   `json:"messages"`
	Temperature    float64           `json:"temperature"`
	ResponseFormat map[string]string `json:"response_format"`
	MaxTokens      int               `json:"max_tokens,omitempty"`
}

// chat выполняет один вызов DeepSeek в JSON-режиме и возвращает текст ответа.
func (d *DeepSeek) chat(ctx context.Context, systemMsg, userMsg string) (string, error) {
	reqBody := dsRequest{
		Model: d.model,
		Messages: []openAIMessage{
			{Role: "system", Content: systemMsg},
			{Role: "user", Content: userMsg},
		},
		Temperature:    0,
		ResponseFormat: map[string]string{"type": "json_object"},
		MaxTokens:      d.maxTokens,
	}
	body, err := json.Marshal(reqBody)
	if err != nil {
		return "", fmt.Errorf("deepseek: marshal: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, deepSeekEndpoint, bytes.NewReader(body))
	if err != nil {
		return "", fmt.Errorf("deepseek: new request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+d.apiKey)

	resp, err := d.client.Do(req)
	if err != nil {
		return "", fmt.Errorf("deepseek: do: %w", err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", &APIError{
			StatusCode: resp.StatusCode,
			RetryAfter: resp.Header.Get("Retry-After"),
			Body:       truncateRunes(string(raw), 500),
		}
	}

	var parsed openAIResponse
	if err := json.Unmarshal(raw, &parsed); err != nil {
		return "", fmt.Errorf("deepseek: unmarshal envelope: %w", err)
	}
	if parsed.Error != nil {
		return "", fmt.Errorf("deepseek: api error: %s", parsed.Error.Message)
	}
	if len(parsed.Choices) == 0 {
		return "", fmt.Errorf("deepseek: пустой ответ (нет choices)")
	}
	return parsed.Choices[0].Message.Content, nil
}

// stripSchemaDescriptions убирает поле "description" из JSON Schema —
// смысл каждого поля уже расписан прозой в systemPrompt/ratesSystemPrompt
// (см. схема.go: описания там почти буквально повторяют правила выше),
// повторять его текстом для DeepSeek — чистый лишний токен на каждый вызов.
// Оставляем только структуру: type/required/enum/properties/items — то,
// чего в прозе нет (напр. что rate_tiers[] — объект {term_min,term_max,
// amount_min,amount_max,rate}).
func stripSchemaDescriptions(v any) any {
	switch t := v.(type) {
	case map[string]any:
		out := make(map[string]any, len(t))
		for k, vv := range t {
			if k == "description" {
				continue
			}
			out[k] = stripSchemaDescriptions(vv)
		}
		return out
	case []any:
		out := make([]any, len(t))
		for i, vv := range t {
			out[i] = stripSchemaDescriptions(vv)
		}
		return out
	default:
		return v
	}
}

// schemaPromptText сериализует JSON Schema как компактный текст для
// DeepSeek — единственный провайдер без strict json_schema (см. коммент
// у DeepSeek выше): схему модель иначе не видит совсем, только упоминания
// имён полей в прозе правил — подтверждено на проде: rate=0 у Арванда
// (interest_rate="31% - 32%" без схемы rate_tiers модель не поняла, куда
// класть текстовый диапазон).
func schemaPromptText(schema map[string]any) string {
	b, _ := json.Marshal(stripSchemaDescriptions(schema))
	return "\n\nСтрого следуй этой JSON Schema (draft 2020-12) для формы ответа:\n" + string(b)
}

// Схема статична — считаем текст один раз при старте, не на каждый вызов.
var (
	productSchemaText = schemaPromptText(responseSchema())
	ratesSchemaText   = schemaPromptText(ratesSchema())
)

// Extract реализует AIExtractor (продукты).
func (d *DeepSeek) Extract(ctx context.Context, markdown string, category model.Category) (*Extraction, error) {
	rawText, err := d.chat(ctx, systemPrompt+productSchemaText, userPrompt(markdown, category))
	if err != nil {
		return nil, fmt.Errorf("deepseek: %w", err)
	}
	result, err := decodeExtraction(rawText)
	if err != nil {
		return &Extraction{RawResponse: rawText}, fmt.Errorf("deepseek: %w", err)
	}
	return &Extraction{Result: result, RawResponse: rawText}, nil
}

// ExtractRates реализует RatesExtractor (курсы).
func (d *DeepSeek) ExtractRates(ctx context.Context, markdown, notes string) (*RatesExtraction, error) {
	rawText, err := d.chat(ctx, ratesSystemPrompt+ratesSchemaText, ratesUserPrompt(markdown, notes))
	if err != nil {
		return nil, fmt.Errorf("deepseek: %w", err)
	}
	result, err := decodeRates(rawText)
	if err != nil {
		return &RatesExtraction{RawResponse: rawText}, fmt.Errorf("deepseek: %w", err)
	}
	return &RatesExtraction{Result: result, RawResponse: rawText}, nil
}
