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

// ScraperProvider — провайдер скрейпинга HTML→Markdown.
type ScraperProvider string

const (
	ScraperFirecrawl ScraperProvider = "firecrawl"
	ScraperJina      ScraperProvider = "jina"
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

	// ScraperProvider — firecrawl | jina.
	ScraperProvider ScraperProvider
	// ScraperAPIKey — ключ скрейпера. Секрет.
	ScraperAPIKey string

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

	// HTTPTimeout — таймаут на скрейп одной страницы.
	HTTPTimeout time.Duration
	// AITimeout — таймаут на один вызов AI.
	AITimeout time.Duration
}

// Load читает и валидирует конфигурацию из окружения.
// Возвращает ошибку, если обязательные значения отсутствуют или некорректны.
func Load() (*Config, error) {
	cfg := &Config{
		DatabaseURL:     os.Getenv("DATABASE_URL"),
		DebugLog:        parseBool(os.Getenv("PARSER_DEBUG_LOG"), false),
		ScraperProvider: ScraperProvider(strings.ToLower(os.Getenv("SCRAPER_PROVIDER"))),
		ScraperAPIKey:   os.Getenv("SCRAPER_API_KEY"),
		AIProvider:      AIProvider(strings.ToLower(os.Getenv("AI_PROVIDER"))),
		AIAPIKey:        os.Getenv("AI_API_KEY"),
		AIModel:         os.Getenv("AI_MODEL"),
		MaxTokens:       parseInt(os.Getenv("PARSER_MAX_TOKENS"), 8000),
		Concurrency:     parseInt(os.Getenv("PARSER_CONCURRENCY"), 1),
		HTTPTimeout:     time.Duration(parseInt(os.Getenv("PARSER_HTTP_TIMEOUT_SEC"), 60)) * time.Second,
		AITimeout:       time.Duration(parseInt(os.Getenv("PARSER_AI_TIMEOUT_SEC"), 120)) * time.Second,
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

	switch c.ScraperProvider {
	case ScraperFirecrawl, ScraperJina:
	default:
		return fmt.Errorf("config: SCRAPER_PROVIDER должен быть firecrawl|jina, получено %q", c.ScraperProvider)
	}

	switch c.AIProvider {
	case AIGemini, AIQwen, AIOpenRouter, AIDeepSeek:
	default:
		return fmt.Errorf("config: AI_PROVIDER должен быть gemini|qwen|openrouter|deepseek, получено %q", c.AIProvider)
	}

	// Firecrawl требует ключ; Jina Reader работает и в бесплатном keyless-режиме
	// (https://r.jina.ai), поэтому для jina ключ опционален.
	if c.ScraperAPIKey == "" && c.ScraperProvider == ScraperFirecrawl {
		return fmt.Errorf("config: SCRAPER_API_KEY обязателен для firecrawl (для jina ключ опционален)")
	}
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
