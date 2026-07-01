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

// defaultOpenRouterModel — модель по умолчанию (переопределяется AI_MODEL).
// Бесплатный tier OpenRouter: суффикс :free обязателен, иначе провайдер
// списывает кредиты. Список — https://openrouter.ai/collections/free-models.
// DeepSeek V3.1 выбран как наиболее стабильный из free-моделей для JSON-режима.
const defaultOpenRouterModel = "deepseek/deepseek-chat-v3.1:free"

// openRouterEndpoint — OpenAI-совместимый chat/completions OpenRouter.
const openRouterEndpoint = "https://openrouter.ai/api/v1/chat/completions"

// OpenRouter — реализация AIExtractor через OpenAI-совместимый API OpenRouter
// в JSON-режиме (response_format json_schema, strict). Переиспользует
// openAIRequest/openAIResponse/responseFormat из qwen.go (один пакет).
type OpenRouter struct {
	apiKey    string
	model     string
	maxTokens int
	client    *http.Client
}

// defaultMaxTokens — потолок вывода по умолчанию, если не задан PARSER_MAX_TOKENS.
const defaultMaxTokens = 8000

// NewOpenRouter создаёт экстрактор OpenRouter. maxTokens<=0 → defaultMaxTokens.
func NewOpenRouter(apiKey, modelName string, maxTokens int, client *http.Client) *OpenRouter {
	if modelName == "" {
		modelName = defaultOpenRouterModel
	}
	if maxTokens <= 0 {
		maxTokens = defaultMaxTokens
	}
	return &OpenRouter{apiKey: apiKey, model: modelName, maxTokens: maxTokens, client: client}
}

// Extract реализует AIExtractor.
func (o *OpenRouter) Extract(ctx context.Context, markdown string, category model.Category) (*Extraction, error) {
	reqBody := openAIRequest{
		Model: o.model,
		Messages: []openAIMessage{
			{Role: "system", Content: systemPrompt},
			{Role: "user", Content: userPrompt(markdown, category)},
		},
		Temperature: 0,
		// Ограничиваем вывод, иначе OpenRouter резервирует максимум модели и
		// требует покрытия лимитом ключа авансом (HTTP 402). Настраивается
		// PARSER_MAX_TOKENS; при низком лимите ключа поднять лимит в OpenRouter.
		MaxTokens: o.maxTokens,
		ResponseFormat: responseFormat{
			Type: "json_schema",
			JSONSchema: jsonSchemaSpec{
				Name:   "parsed_products",
				Strict: true,
				Schema: responseSchema(),
			},
		},
	}
	body, err := json.Marshal(reqBody)
	if err != nil {
		return nil, fmt.Errorf("openrouter: marshal: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, openRouterEndpoint, bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("openrouter: new request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+o.apiKey)
	// Опциональные заголовки атрибуции OpenRouter (для рейтинга/дашборда).
	req.Header.Set("X-Title", "Sravni.tj parser")

	resp, err := o.client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("openrouter: do: %w", err)
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
		return nil, fmt.Errorf("openrouter: unmarshal envelope: %w", err)
	}
	if parsed.Error != nil {
		return nil, fmt.Errorf("openrouter: api error: %s", parsed.Error.Message)
	}
	if len(parsed.Choices) == 0 {
		return nil, fmt.Errorf("openrouter: пустой ответ (нет choices)")
	}

	rawText := parsed.Choices[0].Message.Content
	result, err := decodeExtraction(rawText)
	if err != nil {
		return &Extraction{RawResponse: rawText}, fmt.Errorf("openrouter: %w", err)
	}
	return &Extraction{Result: result, RawResponse: rawText}, nil
}
