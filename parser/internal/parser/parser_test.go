package parser

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"sync"
	"testing"
	"time"

	"sravni/parser/internal/config"
	"sravni/parser/internal/extract"
	"sravni/parser/internal/model"
	"sravni/parser/internal/scrape"
	"sravni/parser/internal/store"
)

// --- Моки ---

// mockStore — потокобезопасный мок store.Store.
type mockStore struct {
	mu        sync.Mutex
	tasks     []model.SourceTask
	upserts   []store.ProductWrite
	runs      []store.RunLog
	outdated  int
	upsertErr error
}

func (m *mockStore) ActiveTasks(ctx context.Context) ([]model.SourceTask, error) {
	return m.tasks, nil
}

func (m *mockStore) UpsertProduct(ctx context.Context, pw store.ProductWrite) (int64, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.upsertErr != nil {
		return 0, m.upsertErr
	}
	m.upserts = append(m.upserts, pw)
	return int64(len(m.upserts)), nil
}

func (m *mockStore) MarkOutdated(ctx context.Context, sourceURLID int64, runStartedAt time.Time) (int64, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.outdated++
	return 0, nil
}

func (m *mockStore) LogRun(ctx context.Context, rl store.RunLog) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.runs = append(m.runs, rl)
	return nil
}

func (m *mockStore) TouchSourceParsed(ctx context.Context, sourceURLID int64, at time.Time) error {
	return nil
}

// Методы discovery — не используются в тестах продуктового парсера (заглушки).
func (m *mockStore) DiscoveryInstructions(ctx context.Context) ([]model.DiscoveryInstruction, error) {
	return nil, nil
}

func (m *mockStore) UpsertSourceURL(ctx context.Context, bankID int64, category model.Category, url string) (bool, error) {
	return false, nil
}

func (m *mockStore) TouchInstruction(ctx context.Context, instructionID int64, at time.Time) error {
	return nil
}

func (m *mockStore) RatesInstructions(ctx context.Context) ([]model.DiscoveryInstruction, error) {
	return nil, nil
}

func (m *mockStore) UpsertRate(ctx context.Context, rw store.RateWrite) error {
	return nil
}

func (m *mockStore) Close() {}

func (m *mockStore) runCount() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	return len(m.runs)
}

func (m *mockStore) upsertCount() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	return len(m.upserts)
}

// mockScraper всегда возвращает фиксированный markdown.
type mockScraper struct{ err error }

func (m *mockScraper) Scrape(ctx context.Context, url string) (string, error) {
	if m.err != nil {
		return "", m.err
	}
	return "# markdown банка", nil
}

func (m *mockScraper) ScrapeRaw(ctx context.Context, url string) (string, error) {
	if m.err != nil {
		return "", m.err
	}
	return "<html>сырой</html>", nil
}

// mockScrapers оборачивает один mockScraper в Scrapers (Own=Firecrawl=m) —
// в тестах пайплайна выбор own/firecrawl не важен, важно поведение Scraper.
func mockScrapers(m scrape.Scraper) *scrape.Scrapers {
	return &scrape.Scrapers{Own: m, Firecrawl: m}
}

// mockAI возвращает заранее заданный результат извлечения.
type mockAI struct {
	result model.ExtractionResult
	raw    string
	err    error
}

func (m *mockAI) Extract(ctx context.Context, markdown string, category model.Category) (*extract.Extraction, error) {
	if m.err != nil {
		return &extract.Extraction{RawResponse: m.raw}, m.err
	}
	return &extract.Extraction{Result: m.result, RawResponse: m.raw}, nil
}

// --- Хелперы ---

func sp(s string) *string   { return &s }
func ip(i int) *int         { return &i }
func fp(f float64) *float64 { return &f }

func quietLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func baseCfg(debug bool) *config.Config {
	return &config.Config{
		DebugLog:    debug,
		Concurrency: 1,
		HTTPTimeout: 5 * time.Second,
		AITimeout:   5 * time.Second,
	}
}

func validDeposit() model.ParsedProduct {
	return model.ParsedProduct{
		Category:  model.CategoryDeposit,
		Currency:  model.CurrencyTJS,
		NameRU:    sp("Вклад Стандарт"),
		RateMin:   10,
		RateMax:   12,
		AmountMin: fp(1000),
		TermMin:   ip(3),
		TermMax:   ip(12),
		RateTiers: []model.RateTier{
			{TermMin: ip(3), TermMax: ip(6), Rate: 10},
			{TermMin: ip(6), TermMax: ip(12), Rate: 12},
		},
	}
}

func depositTask() model.SourceTask {
	return model.SourceTask{ID: 1, BankID: 7, Category: model.CategoryDeposit, URL: "https://bank.tj/deposits"}
}

// --- Тесты флага PARSER_DEBUG_LOG ---

func TestDebugLog_True_WritesParserRuns(t *testing.T) {
	st := &mockStore{tasks: []model.SourceTask{depositTask()}}
	ai := &mockAI{result: model.ExtractionResult{Products: []model.ParsedProduct{validDeposit()}}, raw: `{"products":[...]}`}
	p := New(baseCfg(true), st, mockScrapers(&mockScraper{}), ai, nil, quietLogger())

	if err := p.Run(context.Background()); err != nil {
		t.Fatalf("Run вернул ошибку: %v", err)
	}
	if st.runCount() != 1 {
		t.Fatalf("при DEBUG_LOG=true ожидалась 1 запись parser_runs, получено %d", st.runCount())
	}
	if st.runs[0].Status != store.RunSuccess {
		t.Fatalf("ожидался статус success, получен %q", st.runs[0].Status)
	}
	if st.runs[0].AIRawResponse == "" {
		t.Fatal("ai_raw_response должен сохраняться при debug-логе")
	}
	if st.upsertCount() != 1 {
		t.Fatalf("ожидался 1 upsert продукта, получено %d", st.upsertCount())
	}
}

func TestDebugLog_False_NoParserRuns(t *testing.T) {
	st := &mockStore{tasks: []model.SourceTask{depositTask()}}
	ai := &mockAI{result: model.ExtractionResult{Products: []model.ParsedProduct{validDeposit()}}}
	p := New(baseCfg(false), st, mockScrapers(&mockScraper{}), ai, nil, quietLogger())

	if err := p.Run(context.Background()); err != nil {
		t.Fatalf("Run вернул ошибку: %v", err)
	}
	// Инвариант §6: флаг влияет ТОЛЬКО на parser_runs, не на products.
	if st.runCount() != 0 {
		t.Fatalf("при DEBUG_LOG=false parser_runs не пишется, получено %d", st.runCount())
	}
	if st.upsertCount() != 1 {
		t.Fatalf("результат в products идентичен независимо от флага: ожидался 1 upsert, получено %d", st.upsertCount())
	}
}

// --- Тесты классификации/частичной отбраковки ---

func TestPartialRejection_ValidWrittenInvalidSkipped(t *testing.T) {
	bad := validDeposit()
	bad.RateTiers = []model.RateTier{{TermMin: ip(3), TermMax: ip(12), Rate: 0}} // 0% → отбраковка
	bad.RateMin, bad.RateMax = 0, 0
	bad.NameRU = sp("Битый вклад")

	st := &mockStore{tasks: []model.SourceTask{depositTask()}}
	ai := &mockAI{result: model.ExtractionResult{Products: []model.ParsedProduct{validDeposit(), bad}}}
	p := New(baseCfg(true), st, mockScrapers(&mockScraper{}), ai, nil, quietLogger())

	if err := p.Run(context.Background()); err != nil {
		t.Fatalf("Run: %v", err)
	}
	if st.upsertCount() != 1 {
		t.Fatalf("валидный продукт записан, битый отбракован: ожидался 1 upsert, получено %d", st.upsertCount())
	}
	if st.runs[0].Status != store.RunPartial {
		t.Fatalf("ожидался статус partial (часть отбракована), получен %q", st.runs[0].Status)
	}
}

func TestScrapeError_NoUpsert_RunError(t *testing.T) {
	st := &mockStore{tasks: []model.SourceTask{depositTask()}}
	p := New(baseCfg(true), st, mockScrapers(&mockScraper{err: errors.New("boom")}), &mockAI{}, nil, quietLogger())

	if err := p.Run(context.Background()); err != nil {
		t.Fatalf("Run не должен возвращать ошибку при провале задачи (§7.3): %v", err)
	}
	if st.upsertCount() != 0 {
		t.Fatal("при ошибке скрейпа продукты не пишутся")
	}
	if st.runs[0].Status != store.RunError {
		t.Fatalf("ожидался статус error, получен %q", st.runs[0].Status)
	}
}

func TestEmptyProducts_NoOutdating(t *testing.T) {
	st := &mockStore{tasks: []model.SourceTask{depositTask()}}
	ai := &mockAI{result: model.ExtractionResult{Products: nil}} // AI вернул []
	p := New(baseCfg(true), st, mockScrapers(&mockScraper{}), ai, nil, quietLogger())

	if err := p.Run(context.Background()); err != nil {
		t.Fatalf("Run: %v", err)
	}
	if st.upsertCount() != 0 {
		t.Fatal("пустой результат — нет upsert")
	}
	if st.outdated != 0 {
		t.Fatal("при пустом результате устаревание НЕ применяется (§8.3)")
	}
	if st.runs[0].Status != store.RunPartial {
		t.Fatalf("ожидался статус partial для пустого результата, получен %q", st.runs[0].Status)
	}
}

func TestExternalKey_StableAcrossWhitespaceAndCase(t *testing.T) {
	a := validDeposit()
	a.NameRU = sp("  Вклад   Стандарт ")
	b := validDeposit()
	b.NameRU = sp("вклад стандарт")
	if externalKey(a) != externalKey(b) {
		t.Fatalf("ключ должен быть устойчив к регистру/пробелам: %q != %q", externalKey(a), externalKey(b))
	}
}

func TestExternalKey_DiffersByCurrency(t *testing.T) {
	a := validDeposit()
	a.Currency = model.CurrencyTJS
	b := validDeposit()
	b.Currency = model.CurrencyUSD
	if externalKey(a) == externalKey(b) {
		t.Fatal("ключ должен различаться по валюте (split-by-currency)")
	}
}
