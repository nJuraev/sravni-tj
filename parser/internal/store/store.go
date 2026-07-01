// Package store — слой доступа к PostgreSQL (pgx pool).
//
// Парсер — ЕДИНСТВЕННЫЙ писатель в products / product_rates / parser_runs
// (specs/parser.md §1, §10). Чтение задач — из bank_source_urls (is_active=true).
package store

import (
	"context"
	"time"

	"sravni/parser/internal/model"
)

// RunStatus — статус задачи для записи в parser_runs.status.
// БД допускает только {success, error, partial} (schema.md §6, chk_runs_status),
// поэтому детальные классы (scrape_error/...) маппятся в один из этих трёх.
type RunStatus string

const (
	RunSuccess RunStatus = "success" // успешно записаны продукты
	RunError   RunStatus = "error"   // фатальная ошибка задачи (scrape/ai/db)
	RunPartial RunStatus = "partial" // записаны не все (часть отбракована) либо пусто
)

// ProductWrite — подготовленная к записи единица: один продукт (одна валюта) +
// его тарифная сетка. Оркестратор формирует это после split-by-currency.
type ProductWrite struct {
	BankID      int64
	SourceURLID int64
	ExternalKey string // стабильный ключ идемпотентности: normalize(name)+currency
	Product     model.ParsedProduct
	ParsedAt    time.Time
}

// RateWrite — подготовленный к записи курс валюты (одна строка bank_currency_rates).
type RateWrite struct {
	BankID   int64
	Currency string
	Category string // cash | transfer
	Buy      *float64
	Sell     *float64
	RateDate time.Time
	ParsedAt time.Time
}

// RunLog — запись метаданных запуска (только при PARSER_DEBUG_LOG=true).
type RunLog struct {
	BankSourceURLID int64
	StartedAt       time.Time
	FinishedAt      time.Time
	Status          RunStatus
	AIRawResponse   string // сырой ответ AI; секреты НЕ включаются
	InputMarkdown   string // markdown, отправленный в AI (вход); пусто → NULL
	ErrorMessage    string // пусто → NULL
	ProductsUpserted int
}

// Store — абстракция персистентности. Интерфейс позволяет мокать БД в тестах
// (в т.ч. для проверки флага PARSER_DEBUG_LOG).
type Store interface {
	// ActiveTasks читает задачи парсинга: bank_source_urls WHERE is_active=true.
	ActiveTasks(ctx context.Context) ([]model.SourceTask, error)

	// DiscoveryInstructions читает активные инструкции discovery
	// (bank_parse_instructions WHERE kind='product_discovery' AND is_active=true).
	DiscoveryInstructions(ctx context.Context) ([]model.DiscoveryInstruction, error)

	// UpsertSourceURL идемпотентно добавляет/реактивирует источник в
	// bank_source_urls по уникальному url. Возвращает true, если строка создана
	// (false — если уже существовала и была обновлена). Найденные discovery URL
	// активируются (is_active=true).
	UpsertSourceURL(ctx context.Context, bankID int64, category model.Category, url string) (bool, error)

	// TouchInstruction обновляет last_run_at инструкции после обработки.
	TouchInstruction(ctx context.Context, instructionID int64, at time.Time) error

	// RatesInstructions читает активные инструкции курсов
	// (bank_parse_instructions WHERE kind='rates' AND is_active=true).
	// Category в таких строках всегда NULL (в результат не попадает).
	RatesInstructions(ctx context.Context) ([]model.DiscoveryInstruction, error)

	// UpsertRate идемпотентно пишет курс по ключу (bank_id, currency, category, rate_date).
	UpsertRate(ctx context.Context, rw RateWrite) error

	// UpsertProduct идемпотентно записывает один продукт + его тарифную сетку
	// в одной транзакции по ключу (source_url_id, external_key). Возвращает id.
	// Уважает администраторский status (не перетирает на active, §8.2).
	UpsertProduct(ctx context.Context, pw ProductWrite) (int64, error)

	// MarkOutdated переводит в 'outdated' продукты источника, не обновлённые в
	// текущем успешном непустом прогоне (parsed_at < runStartedAt). §8.3.
	// Не трогает уже скрытые администратором (hidden/draft). Возвращает кол-во.
	MarkOutdated(ctx context.Context, sourceURLID int64, runStartedAt time.Time) (int64, error)

	// LogRun пишет запись в parser_runs (вызывается только при DebugLog=true).
	LogRun(ctx context.Context, rl RunLog) error

	// TouchSourceParsed обновляет bank_source_urls.last_parsed_at при успехе.
	TouchSourceParsed(ctx context.Context, sourceURLID int64, at time.Time) error

	// Close освобождает пул соединений.
	Close()
}
