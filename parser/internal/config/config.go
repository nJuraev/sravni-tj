// Package config читает конфигурацию парсера из переменных окружения.
//
// Секреты (DATABASE_URL, *_API_KEY) читаются только из env и НИКОГДА не
// логируются и не попадают в parser_runs (см. specs/parser.md §2.2).
package config

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

// AIProvider — провайдер AI для извлечения структуры.
type AIProvider string

const (
	AIGemini     AIProvider = "gemini"
	AIQwen       AIProvider = "qwen"
	AIOpenRouter AIProvider = "openrouter"
	AIDeepSeek   AIProvider = "deepseek" // прямой API DeepSeek (api.deepseek.com), json_object
)

// Config — полная конфигурация одного запуска парсера.
type Config struct {
	// DatabaseURL — DSN PostgreSQL. Секрет.
	DatabaseURL string

	// DebugLog включает запись метаданных каждой задачи в parser_runs.
	DebugLog bool

	// ScraperAPIKey — ключ Firecrawl. Секрет. Опционален: нужен, только если
	// в БД есть источники с scraper='firecrawl' (см. internal/scrape.Scrapers.For) —
	// остальные идут через свой скрейпер (internal/scrape.Direct), ключа не требующий.
	ScraperAPIKey string

	// BrowserCDPURL — HTTP-адрес DevTools своего headless-Chrome (см.
	// scrape.ModeBrowser). Пусто = скрейпер browser недоступен, задачи с
	// scraper='browser' будут падать с понятной ошибкой при попытке скрейпа.
	BrowserCDPURL string

	// AIProvider — gemini | qwen.
	AIProvider AIProvider
	// AIAPIKey — ключ AI. Секрет.
	AIAPIKey string
	// AIModel — конкретная модель (опционально, есть дефолт по провайдеру).
	AIModel string
	// MaxTokens — лимит вывода AI (OpenRouter резервирует его авансом против
	// лимита ключа; слишком большой → HTTP 402). 0 = дефолт экстрактора.
	MaxTokens int

	// Concurrency — максимум одновременно обрабатываемых задач.
	Concurrency int

	// BankIDs — опциональный фильтр по банкам (PARSER_BANK_IDS, CSV id).
	// Пусто (по умолчанию) = без фильтра, обрабатываются все активные задачи.
	// Только для ручных точечных прогонов/отладки — не меняет данные в БД.
	BankIDs []int64

	// HTTPTimeout — таймаут на скрейп одной страницы.
	HTTPTimeout time.Duration
	// AITimeout — таймаут на один вызов AI.
	AITimeout time.Duration
}

// Load читает и валидирует конфигурацию из окружения.
// Возвращает ошибку, если обязательные значения отсутствуют или некорректны.
func Load() (*Config, error) {
	cfg := &Config{
		DatabaseURL:   os.Getenv("DATABASE_URL"),
		DebugLog:      parseBool(os.Getenv("PARSER_DEBUG_LOG"), false),
		ScraperAPIKey: os.Getenv("SCRAPER_API_KEY"),
		BrowserCDPURL: os.Getenv("BROWSER_CDP_URL"),
		AIProvider:    AIProvider(strings.ToLower(os.Getenv("AI_PROVIDER"))),
		AIAPIKey:      os.Getenv("AI_API_KEY"),
		AIModel:       os.Getenv("AI_MODEL"),
		MaxTokens:     parseInt(os.Getenv("PARSER_MAX_TOKENS"), 8000),
		Concurrency:   parseInt(os.Getenv("PARSER_CONCURRENCY"), 1),
		BankIDs:       parseInt64List(os.Getenv("PARSER_BANK_IDS")),
		HTTPTimeout:   time.Duration(parseInt(os.Getenv("PARSER_HTTP_TIMEOUT_SEC"), 60)) * time.Second,
		AITimeout:     time.Duration(parseInt(os.Getenv("PARSER_AI_TIMEOUT_SEC"), 120)) * time.Second,
	}

	if err := cfg.validate(); err != nil {
		return nil, err
	}
	return cfg, nil
}

// validate проверяет обязательные поля и допустимые enum-значения.
func (c *Config) validate() error {
	if c.DatabaseURL == "" {
		return fmt.Errorf("config: DATABASE_URL обязателен")
	}

	switch c.AIProvider {
	case AIGemini, AIQwen, AIOpenRouter, AIDeepSeek:
	default:
		return fmt.Errorf("config: AI_PROVIDER должен быть gemini|qwen|openrouter|deepseek, получено %q", c.AIProvider)
	}

	// SCRAPER_API_KEY не обязателен на старте: нужен только если в БД реально
	// есть источник с scraper='firecrawl' — тогда его отсутствие уронит
	// конкретную задачу (ошибка авторизации Firecrawl), не весь процесс.
	if c.AIAPIKey == "" {
		return fmt.Errorf("config: AI_API_KEY обязателен")
	}

	if c.Concurrency < 1 {
		return fmt.Errorf("config: PARSER_CONCURRENCY должен быть >= 1, получено %d", c.Concurrency)
	}
	return nil
}

// parseBool разбирает булеву env-переменную, при ошибке возвращает default.
func parseBool(s string, def bool) bool {
	if s == "" {
		return def
	}
	v, err := strconv.ParseBool(strings.TrimSpace(s))
	if err != nil {
		return def
	}
	return v
}

// parseInt разбирает целочисленную env-переменную, при ошибке возвращает default.
func parseInt(s string, def int) int {
	if s == "" {
		return def
	}
	v, err := strconv.Atoi(strings.TrimSpace(s))
	if err != nil {
		return def
	}
	return v
}

// parseInt64List разбирает CSV-список id ("1,5" → [1,5]). Пустая/некорректная
// строка → nil (без фильтра). Некорректные элементы молча пропускаются.
func parseInt64List(s string) []int64 {
	if strings.TrimSpace(s) == "" {
		return nil
	}
	var out []int64
	for _, part := range strings.Split(s, ",") {
		part = strings.TrimSpace(part)
		if part == "" {
			continue
		}
		v, err := strconv.ParseInt(part, 10, 64)
		if err != nil {
			continue
		}
		out = append(out, v)
	}
	return out
}
