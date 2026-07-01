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

// RatesExtraction — результат извлечения курсов + сырой ответ модели.
type RatesExtraction struct {
	Result      model.RatesResult
	RawResponse string
}

// RatesExtractor — абстракция «Markdown страницы курсов → строки курсов».
type RatesExtractor interface {
	// ExtractRates извлекает курсы. notes — подсказка из инструкции банка
	// (какие вкладки соответствуют cash/transfer, что игнорировать).
	ExtractRates(ctx context.Context, markdown, notes string) (*RatesExtraction, error)
}

// NewRates возвращает экстрактор курсов. Сейчас поддержан OpenRouter
// (продакшен-провайдер); для остальных — ошибка (добавляется по мере надобности).
func NewRates(cfg *config.Config, client *http.Client) (RatesExtractor, error) {
	switch cfg.AIProvider {
	case config.AIOpenRouter:
		model := cfg.AIModel
		if model == "" {
			model = defaultOpenRouterModel
		}
		return &openRouterRates{apiKey: cfg.AIAPIKey, model: model, client: client}, nil
	case config.AIDeepSeek:
		return NewDeepSeek(cfg.AIAPIKey, cfg.AIModel, cfg.MaxTokens, client), nil
	default:
		return nil, fmt.Errorf("extract: rates-экстрактор не поддерживает провайдера %q", cfg.AIProvider)
	}
}

const ratesSystemPrompt = `Ты — система извлечения курсов валют с сайта банка Таджикистана.
Анализируй Markdown страницы и верни СТРОГО JSON по схеме: {"rates": [...]}.

Жёсткие правила:
- Только JSON, без текста вне его. Не выдумывай числа — бери со страницы как есть.
- Базовая валюта — сомони (TJS); курсы — цена 1 единицы иностранной валюты в TJS.
- Категории (category):
  - "cash" — курсы покупки/продажи наличных для ФИЗИЧЕСКИХ лиц (касса/обмен).
  - "transfer" — курсы для ДЕНЕЖНЫХ ПЕРЕВОДОВ.
- buy = «Банк покупает» (клиент продаёт валюту банку); sell = «Банк продаёт» (клиент покупает).
- Если сторона не указана — null.
- currency — ISO-код заглавными (USD, EUR, RUB, CNY, KZT, AED, TRY, …).
- ИГНОРИРУЙ: курсы для юридических лиц, стоимость золотых слитков, курс погашения кредита,
  справочный «Курс НБТ»/курс Нацбанка (это не курс самого банка).
- Если страница содержит несколько таблиц/вкладок — раздели строки по category.
- Если на странице нет курсов — верни {"rates": []}.
- ВАЖНО: данные могут быть НЕ в видимой таблице, а в JSON внутри <script> (SPA).
  Типичные ключи: "exchangeRates":[{"currency","cashDesks":{"purchase","sale"},"transfers":{"purchase","sale"},...}]
  — тут cashDesks → category=cash, transfers → category=transfer, purchase → buy, sale → sell.
  Либо "conversion":[{"currency","buy","sell"}] — это cash buy/sell. Извлекай числа из такого JSON.
- Подсказку из секции «Инструкция:» в начале текста учитывай при определении вкладок и категорий.`

func ratesUserPrompt(markdown, notes string) string {
	var b strings.Builder
	if n := strings.TrimSpace(notes); n != "" {
		b.WriteString("Инструкция: " + n + "\n\n")
	}
	b.WriteString("Markdown страницы курсов:\n")
	b.WriteString(markdown)
	return b.String()
}

// ratesSchema — JSON Schema ответа для strict json_schema режима.
func ratesSchema() map[string]any {
	rateItem := map[string]any{
		"type":                 "object",
		"additionalProperties": false,
		"required":             []string{"currency", "category", "buy", "sell"},
		"properties": map[string]any{
			"currency": map[string]any{"type": "string", "minLength": 3, "maxLength": 3, "description": "ISO-код валюты заглавными (USD, EUR, RUB, …)."},
			"category": map[string]any{"type": "string", "enum": []string{"cash", "transfer"}, "description": "cash — наличные физлицам; transfer — денежные переводы."},
			"buy":      map[string]any{"type": []string{"number", "null"}, "minimum": 0, "description": "Курс покупки банком (банк покупает). null — не котируется."},
			"sell":     map[string]any{"type": []string{"number", "null"}, "minimum": 0, "description": "Курс продажи банком (банк продаёт). null — не котируется."},
		},
	}

	return map[string]any{
		"type":                 "object",
		"additionalProperties": false,
		"required":             []string{"rates"},
		"properties": map[string]any{
			"rates": map[string]any{
				"type":        "array",
				"minItems":    0,
				"maxItems":    100,
				"description": "Курсы валют банка. Только cash/transfer для физлиц; без юрлиц, золота, погашения, курса НБТ.",
				"items":       rateItem,
			},
		},
	}
}

// openRouterRates — реализация RatesExtractor через OpenRouter (OpenAI-совместимый).
type openRouterRates struct {
	apiKey string
	model  string
	client *http.Client
}

func (o *openRouterRates) ExtractRates(ctx context.Context, markdown, notes string) (*RatesExtraction, error) {
	reqBody := openAIRequest{
		Model: o.model,
		Messages: []openAIMessage{
			{Role: "system", Content: ratesSystemPrompt},
			{Role: "user", Content: ratesUserPrompt(markdown, notes)},
		},
		Temperature: 0,
		// Вывод курсов компактный (десятки чисел) — 2000 токенов с запасом.
		MaxTokens: 2000,
		ResponseFormat: responseFormat{
			Type: "json_schema",
			JSONSchema: jsonSchemaSpec{
				Name:   "currency_rates",
				Strict: true,
				Schema: ratesSchema(),
			},
		},
	}
	body, err := json.Marshal(reqBody)
	if err != nil {
		return nil, fmt.Errorf("rates: marshal: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, openRouterEndpoint, bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("rates: new request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+o.apiKey)
	req.Header.Set("X-Title", "Sravni.tj rates parser")

	resp, err := o.client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("rates: do: %w", err)
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
		return nil, fmt.Errorf("rates: unmarshal envelope: %w", err)
	}
	if parsed.Error != nil {
		return nil, fmt.Errorf("rates: api error: %s", parsed.Error.Message)
	}
	if len(parsed.Choices) == 0 {
		return nil, fmt.Errorf("rates: пустой ответ (нет choices)")
	}

	rawText := parsed.Choices[0].Message.Content
	result, err := decodeRates(rawText)
	if err != nil {
		return &RatesExtraction{RawResponse: rawText}, fmt.Errorf("rates: %w", err)
	}
	return &RatesExtraction{Result: result, RawResponse: rawText}, nil
}

// decodeRates строго разбирает JSON-ответ модели в типизированный результат.
func decodeRates(raw string) (model.RatesResult, error) {
	cleaned := stripCodeFence(raw)

	// БЕЗ DisallowUnknownFields (DeepSeek может добавить лишние поля).
	var res model.RatesResult
	dec := json.NewDecoder(strings.NewReader(cleaned))
	if err := dec.Decode(&res); err != nil {
		return model.RatesResult{}, fmt.Errorf("decode rates: %w", err)
	}
	return res, nil
}
