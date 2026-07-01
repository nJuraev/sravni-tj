// Package extract отвечает за этап 2 пайплайна: Markdown → строго
// типизированный JSON по контракту ai-output-schema.md через AI в режиме
// structured output (specs/parser.md §3 этап 2, §4).
//
// Интерфейс AIExtractor позволяет мокать AI в тестах и менять провайдера
// (Gemini ↔ Qwen) без изменения оркестратора. Контракт схемы при смене
// провайдера НЕ меняется.
package extract

import (
	"context"
	"fmt"
	"net/http"

	"sravni/parser/internal/config"
	"sravni/parser/internal/model"
)

// Extraction — результат извлечения: типизированные продукты + сырой ответ
// модели (для записи в parser_runs.ai_raw_response, секреты не включаются).
type Extraction struct {
	Result model.ExtractionResult
	// RawResponse — сырой текст ответа модели ДО парсинга (для отладки галлюцинаций).
	RawResponse string
}

// AIExtractor — абстракция «Markdown → структурированные продукты».
type AIExtractor interface {
	// Extract вызывает AI в structured-output режиме и возвращает типизированный
	// результат. category — категория задачи, передаётся в промпт как подсказка.
	Extract(ctx context.Context, markdown string, category model.Category) (*Extraction, error)
}

// New возвращает реализацию AIExtractor по конфигу.
func New(cfg *config.Config, client *http.Client) (AIExtractor, error) {
	switch cfg.AIProvider {
	case config.AIGemini:
		return NewGemini(cfg.AIAPIKey, cfg.AIModel, client), nil
	case config.AIQwen:
		return NewQwen(cfg.AIAPIKey, cfg.AIModel, client), nil
	case config.AIOpenRouter:
		return NewOpenRouter(cfg.AIAPIKey, cfg.AIModel, cfg.MaxTokens, client), nil
	case config.AIDeepSeek:
		return NewDeepSeek(cfg.AIAPIKey, cfg.AIModel, cfg.MaxTokens, client), nil
	default:
		return nil, fmt.Errorf("extract: неизвестный AI-провайдер %q", cfg.AIProvider)
	}
}

// systemPrompt — системная инструкция: модель работает как экстрактор,
// обязана вернуть строго JSON по схеме, без выдумывания данных.
// (Анти-галлюцинации: §1, §2 ai-output-schema.md.)
const systemPrompt = `Ты — система извлечения данных о банковских продуктах Таджикистана.
Твоя задача — проанализировать Markdown страницы банка и вернуть СТРОГО JSON по предоставленной схеме.

Жёсткие правила:
- Возвращай ТОЛЬКО JSON-объект вида {"products": [...], "product_links": [...]}. Никакого текста вне JSON.
- Не выдумывай данные. Если значения нет на странице — используй null (где схема это допускает).
- Срок ВСЕГДА в месяцах (год = 12). «До востребования» → term_min = null.
- Ставки — проценты годовых, число в [0..100]. Сумма — в валюте currency (не минор-единицы).
- Если минимальная сумма (amount_min) не указана на странице — верни null (НЕ 0).
- Если продукт предлагается в нескольких валютах с разными ставками — верни ОТДЕЛЬНЫЙ объект на каждую валюту.
- rate_tiers — реальная тарифная сетка со страницы. Если ставка единая — один элемент с общими границами.
- rate_min/rate_max должны равняться минимуму/максимуму rate по rate_tiers.
- name_ru обязателен, если на странице есть русское название; name_tg — таджикское; иначе null.
- features: если про признак ничего не сказано — null (не false).
- subcategory: определи подкатегорию. credit → consumer|mortgage|auto|business|agro|education|refinance|pawn; deposit → term|savings|demand|kids; не подходит/неясно — other; installment → null. Если в начале текста есть строка «Раздел меню: …» — учитывай её как подсказку.
- Если на странице нет продуктов нужной категории — верни пустой "products".

Режим каталога (index):
- Если страница — это КАТАЛОГ/меню/список, который лишь ССЫЛАЕТСЯ на отдельные страницы продуктов (а полных условий — ставки/суммы/срока — на самой странице НЕТ), верни "products": [] и заполни "product_links" объектами {"url": "<абсолютная ссылка>", "section": "<заголовок раздела меню или null>"}. Только продукты нужной категории для физлиц, без повторов, без внешних доменов.
- Если полные условия продуктов есть прямо на странице — верни "products" и пустой "product_links".
- Не клади в "product_links" ссылки на разделы-агрегаторы, рекламу, новости, формы — только конкретные страницы продуктов.`

// userPrompt формирует пользовательское сообщение с категорией-подсказкой и Markdown.
func userPrompt(markdown string, category model.Category) string {
	return fmt.Sprintf(
		"Категория продуктов на этой странице: %s.\nИзвлеки все продукты этой категории.\n\nMarkdown страницы:\n%s",
		category, markdown,
	)
}
