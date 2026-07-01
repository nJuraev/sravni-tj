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

// defaultQwenModel — модель по умолчанию (переопределяется AI_MODEL).
const defaultQwenModel = "qwen-plus"

// qwenEndpoint — OpenAI-совместимый chat/completions DashScope.
const qwenEndpoint = "https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions"

// APIError — не-2xx ответ AI-провайдера. RetryAfter уважается оркестратором (§7.2).
type APIError struct {
	StatusCode int
	RetryAfter string
	Body       string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("ai: HTTP %d: %s", e.StatusCode, e.Body)
}

// Qwen — реализация AIExtractor через OpenAI-совместимый API (DashScope/Qwen)
// в JSON-режиме (response_format json_schema, strict).
type Qwen struct {
	apiKey string
	model  string
	client *http.Client
}

// NewQwen создаёт экстрактор Qwen.
func NewQwen(apiKey, modelName string, client *http.Client) *Qwen {
	if modelName == "" {
		modelName = defaultQwenModel
	}
	return &Qwen{apiKey: apiKey, model: modelName, client: client}
}

// openAIRequest — тело chat/completions с принудительной JSON-схемой.
type openAIRequest struct {
	Model          string          `json:"model"`
	Messages       []openAIMessage `json:"messages"`
	Temperature    float64         `json:"temperature"`
	ResponseFormat responseFormat  `json:"response_format"`
	// MaxTokens ограничивает вывод. Без него OpenRouter резервирует максимум
	// модели (65536) и требует, чтобы лимит ключа покрывал его авансом → 402.
	// omitempty: 0 = не отправлять (старое поведение для Gemini/Qwen).
	MaxTokens int `json:"max_tokens,omitempty"`
}

type openAIMessage struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

type responseFormat struct {
	Type       string         `json:"type"`
	JSONSchema jsonSchemaSpec `json:"json_schema"`
}

type jsonSchemaSpec struct {
	Name   string         `json:"name"`
	Strict bool           `json:"strict"`
	Schema map[string]any `json:"schema"`
}

// openAIResponse — ответ chat/completions.
type openAIResponse struct {
	Choices []struct {
		Message struct {
			Content string `json:"content"`
		} `json:"message"`
	} `json:"choices"`
	Error *struct {
		Message string `json:"message"`
	} `json:"error"`
}

// Extract реализует AIExtractor.
func (q *Qwen) Extract(ctx context.Context, markdown string, category model.Category) (*Extraction, error) {
	reqBody := openAIRequest{
		Model: q.model,
		Messages: []openAIMessage{
			{Role: "system", Content: systemPrompt},
			{Role: "user", Content: userPrompt(markdown, category)},
		},
		Temperature: 0,
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
		return nil, fmt.Errorf("qwen: marshal: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, qwenEndpoint, bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("qwen: new request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+q.apiKey)

	resp, err := q.client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("qwen: do: %w", err)
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
		return nil, fmt.Errorf("qwen: unmarshal envelope: %w", err)
	}
	if parsed.Error != nil {
		return nil, fmt.Errorf("qwen: api error: %s", parsed.Error.Message)
	}
	if len(parsed.Choices) == 0 {
		return nil, fmt.Errorf("qwen: пустой ответ (нет choices)")
	}

	rawText := parsed.Choices[0].Message.Content
	result, err := decodeExtraction(rawText)
	if err != nil {
		return &Extraction{RawResponse: rawText}, fmt.Errorf("qwen: %w", err)
	}
	return &Extraction{Result: result, RawResponse: rawText}, nil
}

// truncateRunes усекает строку до n рун (безопасно для UTF-8) для логов.
func truncateRunes(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n]) + "…"
}
