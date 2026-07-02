// Package parser — оркестратор пайплайна (specs/parser.md §3, §7, §8).
//
// Для каждой задачи bank_source_urls: scrape → extract → validate →
// split-by-currency → upsert. Ошибки классифицируются (scrape/ai/validation/db),
// транзиентные ретраятся с backoff. Падение одной задачи не валит остальные.
package parser

import (
	"context"
	"log/slog"
	"net/url"
	"strings"
	"sync"
	"time"

	"sravni/parser/internal/config"
	"sravni/parser/internal/extract"
	"sravni/parser/internal/model"
	"sravni/parser/internal/scrape"
	"sravni/parser/internal/store"
	"sravni/parser/internal/validate"
)

// Parser связывает зависимости пайплайна.
type Parser struct {
	cfg     *config.Config
	st      store.Store
	scraper scrape.Scraper
	ai      extract.AIExtractor
	log     *slog.Logger
}

// New создаёт оркестратор.
func New(cfg *config.Config, st store.Store, scraper scrape.Scraper, ai extract.AIExtractor, log *slog.Logger) *Parser {
	return &Parser{cfg: cfg, st: st, scraper: scraper, ai: ai, log: log}
}

// taskOutcome — внутренний результат обработки задачи для логирования run.
type taskOutcome struct {
	status        store.RunStatus
	aiRawResponse string
	inputMarkdown string // markdown, ушедший в AI (для parser_runs)
	errMessage    string
	upserted      int
}

// Run выполняет один прогон: читает активные задачи и обрабатывает их
// (с учётом PARSER_CONCURRENCY). Возвращает ошибку только при фатальном сбое
// (например, не удалось прочитать задачи) — частичные провалы задач = nil (§7.3).
func (p *Parser) Run(ctx context.Context) error {
	tasks, err := p.st.ActiveTasks(ctx)
	if err != nil {
		return err // фатально: процесс не может работать без списка задач
	}
	if len(p.cfg.BankIDs) > 0 {
		tasks = filterTasksByBank(tasks, p.cfg.BankIDs)
		p.log.Info("фильтр PARSER_BANK_IDS применён", "bank_ids", p.cfg.BankIDs, "tasks", len(tasks))
	}
	p.log.Info("старт прогона", "tasks", len(tasks), "concurrency", p.cfg.Concurrency)

	// Ограничение параллелизма через семафор-канал.
	sem := make(chan struct{}, p.cfg.Concurrency)
	var wg sync.WaitGroup

	for _, task := range tasks {
		task := task
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			// Изоляция задач: паника одной не валит весь прогон.
			defer func() {
				if r := recover(); r != nil {
					p.log.Error("паника в задаче", "task_id", task.ID, "recover", r)
				}
			}()
			p.processTask(ctx, task)
		}()
	}
	wg.Wait()
	p.log.Info("прогон завершён")
	return nil
}

// processTask обрабатывает одну задачу целиком, изолированно, и при DebugLog
// пишет parser_runs. Не возвращает ошибку наружу — фиксирует её в логах/run.
func (p *Parser) processTask(ctx context.Context, task model.SourceTask) {
	startedAt := time.Now()
	outcome := p.runPipeline(ctx, task, startedAt)
	finishedAt := time.Now()

	p.log.Info("задача завершена",
		"task_id", task.ID, "url", task.URL, "category", task.Category,
		"status", outcome.status, "upserted", outcome.upserted, "err", outcome.errMessage)

	// parser_runs пишется ТОЛЬКО при PARSER_DEBUG_LOG=true (§6).
	// Флаг влияет ИСКЛЮЧИТЕЛЬНО на этот лог, не на запись в products.
	if p.cfg.DebugLog {
		rl := store.RunLog{
			BankSourceURLID:  task.ID,
			StartedAt:        startedAt,
			FinishedAt:       finishedAt,
			Status:           outcome.status,
			AIRawResponse:    outcome.aiRawResponse,
			InputMarkdown:    outcome.inputMarkdown,
			ErrorMessage:     outcome.errMessage,
			ProductsUpserted: outcome.upserted,
		}
		if err := p.st.LogRun(ctx, rl); err != nil {
			p.log.Error("не удалось записать parser_runs", "task_id", task.ID, "err", err)
		}
	}
}

// runPipeline — собственно конвейер одной задачи. Возвращает outcome для лога.
func (p *Parser) runPipeline(ctx context.Context, task model.SourceTask, startedAt time.Time) (outcome taskOutcome) {
	// Вход (markdown листинга), отправленный в AI, попадает в parser_runs даже
	// при последующей ошибке — для отладки «что именно ушло в модель».
	var markdown string
	defer func() { outcome.inputMarkdown = markdown }()

	// --- Этап 1: SCRAPE (с ретраями транзиентных ошибок) ---
	md, err := retry(ctx, func() (string, error) {
		sctx, cancel := context.WithTimeout(ctx, p.cfg.HTTPTimeout)
		defer cancel()
		return p.scraper.Scrape(sctx, task.URL)
	})
	if err != nil {
		return taskOutcome{status: store.RunError, errMessage: "scrape_error: " + err.Error()}
	}
	markdown = md
	p.log.Info("scrape ok", "task_id", task.ID, "url", task.URL, "markdown_chars", len(markdown))

	// --- Этап 2: EXTRACT (с ретраями) ---
	ext, err := retry(ctx, func() (*extract.Extraction, error) {
		actx, cancel := context.WithTimeout(ctx, p.cfg.AITimeout)
		defer cancel()
		return p.ai.Extract(actx, markdown, task.Category)
	})
	// Сырой ответ AI сохраняем даже при ошибке декодирования (для отладки).
	var aiRaw string
	if ext != nil {
		aiRaw = ext.RawResponse
	}
	if err != nil {
		return taskOutcome{status: store.RunError, aiRawResponse: aiRaw, errMessage: "ai_error: " + err.Error()}
	}

	// Index-режим (auto): если страница — каталог/меню со ссылками на отдельные
	// страницы продуктов, обходим эти ссылки и собираем продукты с них.
	// Иначе берём продукты, извлечённые прямо со страницы (старый путь).
	// productSource хранит URL конкретной страницы, с которой продукт собран
	// (products.source_url) — для index-режима это детальная страница, а НЕ
	// страница-каталог задачи.
	products := toProductSources(ext.Result.Products, task.URL)
	if len(ext.Result.ProductLinks) > 0 {
		p.log.Info("index-режим: обнаружены ссылки на детали",
			"task_id", task.ID, "links", len(ext.Result.ProductLinks))
		products = p.gatherFromLinks(ctx, task, ext.Result.ProductLinks)
	}

	// --- Этап 3: VALIDATE + split-by-currency + Этап 4: UPSERT ---
	upserted, anyRejected, dbErr := p.persistProducts(ctx, task, products)
	if dbErr != nil {
		// Ошибка БД — задача провалена (ретрай уже исчерпан внутри persist).
		return taskOutcome{status: store.RunError, aiRawResponse: aiRaw, errMessage: "db_error: " + dbErr.Error()}
	}

	// --- Пост-обработка: устаревание + last_parsed_at ---
	if upserted == 0 {
		// Пустой результат (AI вернул [] или все отбракованы). §5.4, §8.3:
		// существующие продукты НЕ устаревают (нельзя отличить «нет данных» от «не спарсилось»).
		status := store.RunPartial
		if anyRejected {
			p.log.Warn("все продукты отбракованы", "task_id", task.ID)
		}
		return taskOutcome{status: status, aiRawResponse: aiRaw, upserted: 0,
			errMessage: emptyMessage(anyRejected)}
	}

	// Успешный непустой прогон → устаревание исчезнувших + touch источника.
	if n, err := p.st.MarkOutdated(ctx, task.ID, startedAt); err != nil {
		p.log.Error("не удалось пометить outdated", "task_id", task.ID, "err", err)
	} else if n > 0 {
		p.log.Info("помечено outdated", "task_id", task.ID, "count", n)
	}
	if err := p.st.TouchSourceParsed(ctx, task.ID, startedAt); err != nil {
		p.log.Warn("не удалось обновить last_parsed_at", "task_id", task.ID, "err", err)
	}

	status := store.RunSuccess
	if anyRejected {
		status = store.RunPartial // часть продуктов записана, часть отбракована
	}
	return taskOutcome{status: status, aiRawResponse: aiRaw, upserted: upserted}
}

// persistProducts валидирует продукты, выполняет split-by-currency и upsert.
//
// Возвращает: кол-во записанных, был ли хоть один отбракован, и ошибку БД
// (первая встреченная — она транзиентна и валит задачу для ретрая на след. прогоне).
func (p *Parser) persistProducts(ctx context.Context, task model.SourceTask, products []productSource) (int, bool, error) {
	upserted := 0
	anyRejected := false
	now := time.Now()

	for i := range products {
		// Некоторые провайдеры без strict json_schema (напр. DeepSeek: только
		// prose-промпт, без формальной схемы в запросе) иногда не проставляют
		// поле category, хотя AI явно был проинструктирован извлекать продукты
		// именно этой категории (userPrompt). Подставляем category задачи —
		// это не галлюцинация, а то же значение, что AI и так получил на входе;
		// инвариант §5.3 (категория продукта = категория задачи) не ослабляется.
		if products[i].Product.Category == "" {
			products[i].Product.Category = task.Category
		}
		// Этап 3: валидация (включая совпадение категории с задачей §5.3).
		res, vErr := validate.ValidateProduct(products[i].Product, task.Category)
		if vErr != nil {
			// Отбраковка не валит задачу (§5.4) — логируем и продолжаем.
			anyRejected = true
			p.log.Warn("продукт отбракован", "task_id", task.ID, "reason", vErr.Error())
			continue
		}
		for _, w := range res.Warnings {
			p.log.Warn("валидация: предупреждение", "task_id", task.ID, "warn", w)
		}

		// Split-by-currency: по контракту 1 продукт = 1 валюта. AI уже обязан
		// возвращать отдельный объект на валюту, поэтому здесь продукт атомарен.
		// rate_tiers внутри относятся только к currency продукта.
		pw := store.ProductWrite{
			BankID:      task.BankID,
			SourceURLID: task.ID,
			ExternalKey: externalKey(res.Product),
			Product:     res.Product,
			ParsedAt:    now,
			SourceURL:   products[i].URL,
		}

		// Этап 4: idempotent upsert с ретраями транзиентных ошибок БД.
		_, dbErr := retry(ctx, func() (int64, error) {
			return p.st.UpsertProduct(ctx, pw)
		})
		if dbErr != nil {
			return upserted, anyRejected, dbErr
		}
		upserted++
	}
	return upserted, anyRejected, nil
}

// emptyMessage формирует error_message для пустого результата.
func emptyMessage(anyRejected bool) string {
	if anyRejected {
		return "validation_error: все продукты отбракованы"
	}
	return "" // AI вернул пустой массив — это не ошибка, status=partial/empty
}

// filterTasksByBank оставляет только задачи выбранных банков (PARSER_BANK_IDS).
func filterTasksByBank(tasks []model.SourceTask, bankIDs []int64) []model.SourceTask {
	allow := make(map[int64]bool, len(bankIDs))
	for _, id := range bankIDs {
		allow[id] = true
	}
	out := make([]model.SourceTask, 0, len(tasks))
	for _, t := range tasks {
		if allow[t.BankID] {
			out = append(out, t)
		}
	}
	return out
}

// productSource — продукт вместе с URL страницы, с которой он собран
// (products.source_url). Для прямого пути все продукты задачи делят один
// task.URL; для index-режима у каждого продукта — URL его детальной страницы.
type productSource struct {
	Product model.ParsedProduct
	URL     string
}

// toProductSources оборачивает продукты прямого пути (без index-режима)
// одним и тем же URL — страницей самой задачи.
func toProductSources(products []model.ParsedProduct, url string) []productSource {
	out := make([]productSource, len(products))
	for i, pr := range products {
		out[i] = productSource{Product: pr, URL: url}
	}
	return out
}

// maxDetailPages ограничивает число детальных страниц на один index-источник
// (защита от взрывного роста AI-вызовов и от мусорных ссылок).
const maxDetailPages = 40

// gatherFromLinks обходит ссылки на детальные страницы продуктов (index-режим):
// каждую скрейпит и извлекает, агрегируя продукты. Ошибки отдельных ссылок не
// валят задачу (пропускаются с логом). Рекурсии нет — product_links деталей
// игнорируются. Относительные ссылки резолвятся относительно URL листинга,
// внешние домены отбрасываются.
func (p *Parser) gatherFromLinks(ctx context.Context, task model.SourceTask, links []model.ProductLink) []productSource {
	base, baseErr := url.Parse(task.URL)
	seen := make(map[string]bool)
	var out []productSource
	count := 0

	for _, link := range links {
		s := strings.TrimSpace(link.URL)
		if s == "" {
			continue
		}
		abs := s
		if ref, err := url.Parse(s); err == nil && baseErr == nil {
			abs = base.ResolveReference(ref).String()
		}
		if !sameSite(task.URL, abs) {
			continue // только домен банка (включая поддомены)
		}
		key := normalizeURL(abs)
		if seen[key] {
			continue
		}
		seen[key] = true
		if count >= maxDetailPages {
			p.log.Warn("index: достигнут лимит детальных страниц", "task_id", task.ID, "limit", maxDetailPages)
			break
		}
		count++

		markdown, err := retry(ctx, func() (string, error) {
			sctx, cancel := context.WithTimeout(ctx, p.cfg.HTTPTimeout)
			defer cancel()
			return p.scraper.Scrape(sctx, abs)
		})
		if err != nil {
			p.log.Warn("index: scrape детали не удался", "task_id", task.ID, "url", abs, "err", err)
			continue
		}
		// Гибрид-подсказка: подмешиваем заголовок раздела меню в начало markdown,
		// чтобы AI точнее определил подкатегорию.
		if link.Section != nil {
			if sec := strings.TrimSpace(*link.Section); sec != "" {
				markdown = "Раздел меню: " + sec + "\n\n" + markdown
			}
		}
		ext, err := retry(ctx, func() (*extract.Extraction, error) {
			actx, cancel := context.WithTimeout(ctx, p.cfg.AITimeout)
			defer cancel()
			return p.ai.Extract(actx, markdown, task.Category)
		})
		if err != nil {
			p.log.Warn("index: extract детали не удался", "task_id", task.ID, "url", abs, "err", err)
			continue
		}
		// Вложенные product_links деталей игнорируем — без рекурсии.
		out = append(out, toProductSources(ext.Result.Products, abs)...)
	}
	return out
}

// sameSite сравнивает регистрируемые домены (последние два лейбла) двух URL,
// поэтому поддомены одного банка (credit.dc.tj и dc.tj) считаются «своими».
func sameSite(a, b string) bool {
	ua, err1 := url.Parse(a)
	ub, err2 := url.Parse(b)
	if err1 != nil || err2 != nil {
		return false
	}
	return regDomain(ua.Hostname()) == regDomain(ub.Hostname())
}

// regDomain возвращает последние два лейбла хоста (грубый registrable domain).
func regDomain(h string) string {
	parts := strings.Split(strings.ToLower(h), ".")
	if len(parts) < 2 {
		return strings.ToLower(h)
	}
	return parts[len(parts)-2] + "." + parts[len(parts)-1]
}

// normalizeURL приводит URL к канонической форме только для дедупа ссылок
// внутри одного index-обхода (без fragment/tracking-параметров/хвостового
// слэша, host в нижнем регистре).
func normalizeURL(raw string) string {
	u, err := url.Parse(raw)
	if err != nil {
		return raw
	}
	u.Fragment = ""
	u.Host = strings.ToLower(u.Host)
	u.Scheme = strings.ToLower(u.Scheme)
	if q := u.Query(); len(q) > 0 {
		for _, k := range []string{"utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term", "fbclid", "gclid", "ysclid", "yclid"} {
			q.Del(k)
		}
		u.RawQuery = q.Encode()
	}
	u.Path = strings.TrimSuffix(u.Path, "/")
	return u.String()
}
