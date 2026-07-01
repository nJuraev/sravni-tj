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

// defaultDeepSeekModel — модель по умолчанию (V3, переопределяется AI_MODEL).
const defaultDeepSeekModel = "deepseek-chat"

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

// Extract реализует AIExtractor (продукты).
func (d *DeepSeek) Extract(ctx context.Context, markdown string, category model.Category) (*Extraction, error) {
	rawText, err := d.chat(ctx, systemPrompt, userPrompt(markdown, category))
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
	rawText, err := d.chat(ctx, ratesSystemPrompt, ratesUserPrompt(markdown, notes))
	if err != nil {
		return nil, fmt.Errorf("deepseek: %w", err)
	}
	result, err := decodeRates(rawText)
	if err != nil {
		return &RatesExtraction{RawResponse: rawText}, fmt.Errorf("deepseek: %w", err)
	}
	return &RatesExtraction{Result: result, RawResponse: rawText}, nil
}
