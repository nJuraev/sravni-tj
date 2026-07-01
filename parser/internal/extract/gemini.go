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

// defaultGeminiModel — модель по умолчанию (можно переопределить AI_MODEL).
const defaultGeminiModel = "gemini-1.5-flash"

// geminiBaseURL — REST endpoint generateContent. Ключ передаётся query-параметром.
const geminiBaseURL = "https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s"

// Gemini — реализация AIExtractor через Google Gemini API в structured-output
// режиме (responseMimeType=application/json + responseSchema).
type Gemini struct {
	apiKey string
	model  string
	client *http.Client
}

// NewGemini создаёт экстрактор Gemini.
func NewGemini(apiKey, modelName string, client *http.Client) *Gemini {
	if modelName == "" {
		modelName = defaultGeminiModel
	}
	return &Gemini{apiKey: apiKey, model: modelName, client: client}
}

// geminiRequest — тело запроса generateContent.
type geminiRequest struct {
	SystemInstruction *geminiContent  `json:"systemInstruction,omitempty"`
	Contents          []geminiContent `json:"contents"`
	GenerationConfig  geminiGenConfig `json:"generationConfig"`
}

type geminiContent struct {
	Parts []geminiPart `json:"parts"`
}

type geminiPart struct {
	Text string `json:"text"`
}

type geminiGenConfig struct {
	ResponseMIMEType string         `json:"responseMimeType"`
	ResponseSchema   map[string]any `json:"responseSchema"`
	Temperature      float64        `json:"temperature"`
}

// geminiResponse — ответ generateContent.
type geminiResponse struct {
	Candidates []struct {
		Content struct {
			Parts []geminiPart `json:"parts"`
		} `json:"content"`
	} `json:"candidates"`
	Error *struct {
		Message string `json:"message"`
	} `json:"error"`
}

// Extract реализует AIExtractor.
func (g *Gemini) Extract(ctx context.Context, markdown string, category model.Category) (*Extraction, error) {
	reqBody := geminiRequest{
		SystemInstruction: &geminiContent{Parts: []geminiPart{{Text: systemPrompt}}},
		Contents:          []geminiContent{{Parts: []geminiPart{{Text: userPrompt(markdown, category)}}}},
		GenerationConfig: geminiGenConfig{
			ResponseMIMEType: "application/json",
			ResponseSchema:   responseSchema(),
			Temperature:      0, // детерминированность извлечения
		},
	}
	body, err := json.Marshal(reqBody)
	if err != nil {
		return nil, fmt.Errorf("gemini: marshal: %w", err)
	}

	url := fmt.Sprintf(geminiBaseURL, g.model, g.apiKey)
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("gemini: new request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := g.client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("gemini: do: %w", err)
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

	var parsed geminiResponse
	if err := json.Unmarshal(raw, &parsed); err != nil {
		return nil, fmt.Errorf("gemini: unmarshal envelope: %w", err)
	}
	if parsed.Error != nil {
		return nil, fmt.Errorf("gemini: api error: %s", parsed.Error.Message)
	}
	if len(parsed.Candidates) == 0 || len(parsed.Candidates[0].Content.Parts) == 0 {
		return nil, fmt.Errorf("gemini: пустой ответ (нет кандидатов)")
	}

	// Текст модели (это JSON по схеме) — сохраняем как ai_raw_response.
	rawText := parsed.Candidates[0].Content.Parts[0].Text

	result, err := decodeExtraction(rawText)
	if err != nil {
		// Невалидный относительно схемы JSON = ai_error (ретраится оркестратором).
		return &Extraction{RawResponse: rawText}, fmt.Errorf("gemini: %w", err)
	}
	return &Extraction{Result: result, RawResponse: rawText}, nil
}
