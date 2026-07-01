package extract

import (
	"encoding/json"
	"fmt"
	"strings"

	"sravni/parser/internal/model"
)

// responseSchema возвращает JSON Schema обёртки {"products": [ParsedProduct]},
// точно соответствующую docs/parser/ai-output-schema.md.
//
// Используется как:
//   - Gemini: generationConfig.responseSchema (+ responseMimeType=application/json);
//   - Qwen/OpenAI-совместимые: response_format.json_schema со strict=true.
//
// additionalProperties:false на каждом объекте — анти-галлюцинации (§1.3 схемы).
// Схема строится программно, чтобы один источник истины служил обоим провайдерам.
func responseSchema() map[string]any {
	// Nullable-поля описываются как type-массив ["<type>","null"]
	// (JSON Schema draft 2020-12), что поддерживают и Gemini, и OpenAI-совместимые.
	tierItem := map[string]any{
		"type":                 "object",
		"additionalProperties": false,
		"required":             []string{"term_min", "term_max", "amount_min", "amount_max", "rate"},
		"properties": map[string]any{
			"term_min":   map[string]any{"type": []string{"integer", "null"}, "minimum": 1, "maximum": 600, "description": "Нижняя граница срока тарифа, месяцы. null — не зависит от срока."},
			"term_max":   map[string]any{"type": []string{"integer", "null"}, "minimum": 1, "maximum": 600, "description": "Верхняя граница срока тарифа, месяцы. null — без верхней границы."},
			"amount_min": map[string]any{"type": []string{"number", "null"}, "minimum": 0, "description": "Нижняя граница суммы тарифа в валюте currency. null — не зависит от суммы."},
			"amount_max": map[string]any{"type": []string{"number", "null"}, "minimum": 0, "description": "Верхняя граница суммы тарифа. null — без верхней границы."},
			"rate":       map[string]any{"type": "number", "minimum": 0, "maximum": 100, "description": "Годовая ставка для комбинации срок×сумма, %."},
		},
	}

	features := map[string]any{
		"type":                 "object",
		"additionalProperties": false,
		"description":          "Булевы признаки. Если про признак ничего не сказано — null (неизвестно), НЕ false.",
		"required":             []string{"online_application", "no_guarantor", "capitalization", "replenishable", "early_withdrawal"},
		"properties": map[string]any{
			"online_application": map[string]any{"type": []string{"boolean", "null"}, "description": "Возможно онлайн-оформление без визита в отделение."},
			"no_guarantor":       map[string]any{"type": []string{"boolean", "null"}, "description": "Кредит без поручителя (только для category=credit)."},
			"capitalization":     map[string]any{"type": []string{"boolean", "null"}, "description": "Капитализация процентов (только для category=deposit)."},
			"replenishable":      map[string]any{"type": []string{"boolean", "null"}, "description": "Возможность пополнения вклада / частичного досрочного погашения."},
			"early_withdrawal":   map[string]any{"type": []string{"boolean", "null"}, "description": "Досрочное снятие вклада без потери процентов (только deposit)."},
		},
	}

	product := map[string]any{
		"type":                 "object",
		"additionalProperties": false,
		"required": []string{
			"category", "subcategory", "currency", "name_ru", "name_tg",
			"rate_min", "rate_max", "amount_min", "amount_max",
			"term_min", "term_max", "features", "rate_tiers",
		},
		"properties": map[string]any{
			"category":       map[string]any{"type": "string", "enum": []string{"credit", "deposit", "installment"}, "description": "Тип продукта: credit — кредит/заём, deposit — вклад, installment — рассрочка/исламское финансирование (без ставки, через наценку)."},
			"subcategory": map[string]any{
				"type":        []string{"string", "null"},
				"enum":        []any{"consumer", "mortgage", "auto", "business", "agro", "education", "refinance", "pawn", "term", "savings", "demand", "kids", "other", nil},
				"description": "Подкатегория. Для credit: consumer|mortgage|auto|business|agro|education|refinance|pawn. Для deposit: term|savings|demand|kids. Не подходит/неясно — other. Для installment — null. Если в начале текста есть 'Раздел меню: …' — используй как подсказку.",
			},
			"currency":       map[string]any{"type": "string", "enum": []string{"TJS", "USD", "EUR"}, "description": "Валюта продукта. Несколько валют → отдельный объект на каждую."},
			"name_ru":        map[string]any{"type": []string{"string", "null"}, "minLength": 1, "maxLength": 255, "description": "Название на русском. null, если русского названия нет."},
			"name_tg":        map[string]any{"type": []string{"string", "null"}, "minLength": 1, "maxLength": 255, "description": "Название на таджикском. null, если таджикского названия нет."},
			"description_ru": map[string]any{"type": []string{"string", "null"}, "maxLength": 4000, "description": "Краткое описание на русском. null, если нет."},
			"description_tg": map[string]any{"type": []string{"string", "null"}, "maxLength": 4000, "description": "Краткое описание на таджикском. null, если нет."},
			"rate_min":       map[string]any{"type": "number", "minimum": 0, "maximum": 100, "description": "Минимальная годовая ставка, %. Если единая — равна rate_max."},
			"rate_max":       map[string]any{"type": "number", "minimum": 0, "maximum": 100, "description": "Максимальная годовая ставка, %. Должна быть >= rate_min."},
			"amount_min":     map[string]any{"type": []string{"number", "null"}, "minimum": 0, "description": "Минимальная сумма в валюте currency. null, если минимальная сумма не указана (НЕ 0)."},
			"amount_max":     map[string]any{"type": []string{"number", "null"}, "minimum": 0, "description": "Максимальная сумма. null — без лимита. Если задана — >= amount_min."},
			"term_min":       map[string]any{"type": []string{"integer", "null"}, "minimum": 1, "maximum": 600, "description": "Минимальный срок в месяцах. null для «до востребования»."},
			"term_max":       map[string]any{"type": []string{"integer", "null"}, "minimum": 1, "maximum": 600, "description": "Максимальный срок в месяцах. null — без верхней границы."},
			"features":       features,
			"rate_tiers": map[string]any{
				"type":        "array",
				"minItems":    0,
				"maxItems":    50,
				"description": "Тарифная сетка: ставка зависит от срока И/ИЛИ суммы. Единая ставка → один элемент. Не выдумывай диапазоны.",
				"items":       tierItem,
			},
			"source_note": map[string]any{"type": []string{"string", "null"}, "maxLength": 500, "description": "Необязательная заметка о неоднозначности извлечения. Для отладки."},
		},
	}

	return map[string]any{
		"type":                 "object",
		"additionalProperties": false,
		"required":             []string{"products", "product_links"},
		"properties": map[string]any{
			"products": map[string]any{
				"type":        "array",
				"minItems":    0,
				"maxItems":    30,
				"description": "Все продукты с полными условиями, найденные ПРЯМО на странице.",
				"items":       product,
			},
			"product_links": map[string]any{
				"type":        "array",
				"minItems":    0,
				"maxItems":    60,
				"description": "Если страница — каталог/меню со ссылками на отдельные страницы продуктов (полных условий на ней нет): объекты {url, section} для каждой страницы продукта нужной категории для физлиц (без повторов, без внешних доменов). Иначе пустой массив.",
				"items": map[string]any{
					"type":                 "object",
					"additionalProperties": false,
					"required":             []string{"url", "section"},
					"properties": map[string]any{
						"url":     map[string]any{"type": "string", "description": "Абсолютный URL страницы продукта."},
						"section": map[string]any{"type": []string{"string", "null"}, "description": "Заголовок раздела меню над ссылкой (подсказка подкатегории) или null."},
					},
				},
			},
		},
	}
}

// decodeExtraction строго разбирает JSON-ответ модели в типизированный результат.
//
// raw — текст ответа модели (возможно обёрнутый в ```json ... ```).
// Используется DisallowUnknownFields: лишние поля = ошибка (анти-галлюцинации).
func decodeExtraction(raw string) (model.ExtractionResult, error) {
	cleaned := stripCodeFence(raw)

	// БЕЗ DisallowUnknownFields: DeepSeek (json_object, без strict-схемы) иногда
	// добавляет лишние поля (replenishment, name_en, …). Игнорируем их — значения
	// всё равно валидируются по диапазонам в validate. (OpenRouter strict не пускал лишнее.)
	var res model.ExtractionResult
	dec := json.NewDecoder(strings.NewReader(cleaned))
	if err := dec.Decode(&res); err != nil {
		return model.ExtractionResult{}, fmt.Errorf("decode extraction: %w", err)
	}
	return res, nil
}

// stripCodeFence удаляет обрамление ```json ... ``` / ``` ... ```, если модель
// (вопреки structured output) вернула JSON внутри markdown-блока кода.
func stripCodeFence(s string) string {
	s = strings.TrimSpace(s)
	if !strings.HasPrefix(s, "```") {
		return s
	}
	// Убираем первую строку с открывающим fence (```/```json).
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		s = s[i+1:]
	}
	// Убираем закрывающий fence.
	s = strings.TrimSpace(s)
	s = strings.TrimSuffix(s, "```")
	return strings.TrimSpace(s)
}
