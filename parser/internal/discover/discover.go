// Package discover — отдельный пайплайн поиска страниц продуктов.
//
// Работает по bank_parse_instructions(kind='product_discovery'): scrape
// стартовой страницы → AI извлекает ссылки на страницы продуктов → upsert в
// bank_source_urls (авто is_active=true) для последующего парсинга cmd/parser.
//
// Это НЕ парсинг продуктов: цель — только наполнить список источников.
package discover

import (
	"context"
	"log/slog"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"sravni/parser/internal/config"
	"sravni/parser/internal/extract"
	"sravni/parser/internal/model"
	retryutil "sravni/parser/internal/retry"
	"sravni/parser/internal/scrape"
	"sravni/parser/internal/store"
)

// maxLinksPerInstruction ограничивает число URL с одной стартовой страницы
// (защита от мусорных меню/футеров с сотнями ссылок).
const maxLinksPerInstruction = 60

// Discoverer связывает зависимости discovery-пайплайна.
type Discoverer struct {
	cfg      *config.Config
	st       store.Store
	scrapers *scrape.Scrapers
	ai       extract.AIExtractor
	links    extract.LinksExtractor
	log      *slog.Logger
}

// New создаёт discovery-оркестратор.
func New(cfg *config.Config, st store.Store, scrapers *scrape.Scrapers, ai extract.AIExtractor, links extract.LinksExtractor, log *slog.Logger) *Discoverer {
	return &Discoverer{cfg: cfg, st: st, scrapers: scrapers, ai: ai, links: links, log: log}
}

// Run обрабатывает все активные инструкции discovery (с учётом Concurrency).
// Фатальная ошибка — только если не удалось прочитать инструкции.
func (d *Discoverer) Run(ctx context.Context) error {
	instrs, err := d.st.DiscoveryInstructions(ctx)
	if err != nil {
		return err
	}
	if len(d.cfg.BankIDs) > 0 {
		instrs = filterInstructionsByBank(instrs, d.cfg.BankIDs)
		d.log.Info("фильтр PARSER_BANK_IDS применён", "bank_ids", d.cfg.BankIDs, "instructions", len(instrs))
	}
	single, groups := groupInstructions(instrs)
	if d.links == nil {
		// LinksExtractor недоступен (провайдер не поддержан) — группы идут
		// обычным одиночным путём вместо объединённого, discovery в целом
		// не ломается (см. cmd/discover/main.go).
		for _, g := range groups {
			single = append(single, g...)
		}
		groups = nil
	}
	d.log.Info("старт discovery", "single", len(single), "groups", len(groups), "concurrency", d.cfg.Concurrency)

	sem := make(chan struct{}, d.cfg.Concurrency)
	var wg sync.WaitGroup

	for _, in := range single {
		in := in
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			defer func() {
				if r := recover(); r != nil {
					d.log.Error("паника в инструкции", "instruction_id", in.ID, "recover", r)
				}
			}()
			d.process(ctx, in)
		}()
	}
	for _, g := range groups {
		g := g
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			defer func() {
				if r := recover(); r != nil {
					d.log.Error("паника в группе инструкций", "bank_id", g[0].BankID, "recover", r)
				}
			}()
			d.processGroup(ctx, g)
		}()
	}
	wg.Wait()

	if err := d.registerStaticSources(ctx); err != nil {
		d.log.Warn("discovery: регистрация static_source не удалась", "err", err)
	}

	d.processSequentialIDInstructions(ctx)

	d.log.Info("discovery завершён")
	return nil
}

// groupKey группирует product_discovery-инструкции, у которых одна и та же
// стартовая страница даст ОДИН И ТОТ ЖЕ markdown для разных категорий (напр.
// шапка сайта с меню "Кредиты"+"Вклады" сразу) — такие обрабатываются ОДНИМ
// скрейпом+ОДНИМ AI-вызовом вместо двух с идентичным входом (см. processGroup).
type groupKey struct {
	BankID   int64
	StartURL string
	Scraper  string
}

// groupInstructions разбивает инструкции на одиночные (обычный путь, process)
// и группы из ≥2 инструкций с идентичным (bank_id, start_url, scraper)
// (объединённый путь, processGroup). Банки с разными start_url на категорию
// (большинство) полностью не затронуты — идут в single, как и раньше.
func groupInstructions(instrs []model.DiscoveryInstruction) (single []model.DiscoveryInstruction, groups [][]model.DiscoveryInstruction) {
	byKey := make(map[groupKey][]model.DiscoveryInstruction)
	var order []groupKey
	for _, in := range instrs {
		k := groupKey{BankID: in.BankID, StartURL: in.StartURL, Scraper: in.Scraper}
		if _, ok := byKey[k]; !ok {
			order = append(order, k)
		}
		byKey[k] = append(byKey[k], in)
	}
	for _, k := range order {
		g := byKey[k]
		if len(g) >= 2 {
			groups = append(groups, g)
		} else {
			single = append(single, g[0])
		}
	}
	return single, groups
}

// filterInstructionsByBank оставляет только инструкции выбранных банков (PARSER_BANK_IDS).
func filterInstructionsByBank(instrs []model.DiscoveryInstruction, bankIDs []int64) []model.DiscoveryInstruction {
	allow := make(map[int64]bool, len(bankIDs))
	for _, id := range bankIDs {
		allow[id] = true
	}
	out := make([]model.DiscoveryInstruction, 0, len(instrs))
	for _, in := range instrs {
		if allow[in.BankID] {
			out = append(out, in)
		}
	}
	return out
}

// process обрабатывает одну инструкцию: scrape → extract links → upsert sources.
func (d *Discoverer) process(ctx context.Context, in model.DiscoveryInstruction) {
	startedAt := time.Now()

	markdown, err := retryutil.Do(ctx, func() (string, error) {
		sctx, cancel := context.WithTimeout(ctx, d.cfg.HTTPTimeout)
		defer cancel()
		return scrape.ScrapeForLinks(sctx, d.scrapers.For(in.Scraper), in.StartURL)
	})
	if err != nil {
		d.log.Warn("discovery: scrape стартовой не удался", "instruction_id", in.ID, "url", in.StartURL, "err", err)
		return
	}
	markdown = prependHints(markdown, in)

	// Ретрай транзиентных ошибок (429/5xx, сетевые обрывы, decode пустого
	// content у reasoning-моделей — см. extract.PromptVersion) — та же
	// причина, что и в cmd/parser (retryutil.Do).
	ext, err := retryutil.Do(ctx, func() (*extract.Extraction, error) {
		actx, cancel := context.WithTimeout(ctx, d.cfg.AITimeout)
		defer cancel()
		return d.ai.Extract(actx, in.StartURL, markdown, in.Category)
	})
	if err != nil {
		d.log.Warn("discovery: extract не удался", "instruction_id", in.ID, "url", in.StartURL, "err", err)
		return
	}

	// Кандидаты: ссылки на детали (index-режим). Если ссылок нет, но AI распознал
	// продукты прямо на странице — сама стартовая страница и есть источник.
	var candidates []string
	for _, l := range ext.Result.ProductLinks {
		candidates = append(candidates, l.URL)
	}
	if len(candidates) == 0 && len(ext.Result.Products) > 0 {
		candidates = append(candidates, in.StartURL)
	}

	urls := resolveAndFilter(in.StartURL, candidates)

	inserted, updated := 0, 0
	for _, u := range urls {
		ins, err := d.st.UpsertSourceURL(ctx, in.BankID, in.Category, u, in.Scraper)
		if err != nil {
			d.log.Warn("discovery: upsert источника не удался", "instruction_id", in.ID, "url", u, "err", err)
			continue
		}
		if ins {
			inserted++
		} else {
			updated++
		}
	}

	if err := d.st.TouchInstruction(ctx, in.ID, startedAt); err != nil {
		d.log.Warn("discovery: touch last_run_at не удался", "instruction_id", in.ID, "err", err)
	}

	d.log.Info("инструкция обработана",
		"instruction_id", in.ID, "bank_id", in.BankID, "category", in.Category,
		"found", len(urls), "inserted", inserted, "updated", updated)
}

// prependHints подмешивает подсказки (секции меню, заметку) в начало markdown,
// чтобы AI точнее находил ссылки именно на продукты нужной категории.
func prependHints(markdown string, in model.DiscoveryInstruction) string {
	hint := hintText(in)
	if hint == "" {
		return markdown
	}
	return hint + "\n" + markdown
}

// hintText строит текст подсказки (секции меню + notes) БЕЗ markdown —
// переиспользуется prependHints (одна инструкция) и processGroup (несколько
// инструкций на один и тот же markdown, см. groupInstructions).
func hintText(in model.DiscoveryInstruction) string {
	var b strings.Builder
	if len(in.MenuSections) > 0 {
		b.WriteString("Ищи ссылки на страницы продуктов в разделах меню: ")
		b.WriteString(strings.Join(in.MenuSections, ", "))
		b.WriteString(".\n")
	}
	if in.Notes != nil {
		if note := strings.TrimSpace(*in.Notes); note != "" {
			b.WriteString("Подсказка: " + note + "\n")
		}
	}
	return b.String()
}

// processGroup — объединённый путь для инструкций с идентичным
// (bank_id, start_url, scraper): один скрейп + один AI-вызов на ВСЕ
// категории группы вместо отдельного вызова на каждую с тем же входом.
func (d *Discoverer) processGroup(ctx context.Context, group []model.DiscoveryInstruction) {
	startedAt := time.Now()
	first := group[0]

	markdown, err := retryutil.Do(ctx, func() (string, error) {
		sctx, cancel := context.WithTimeout(ctx, d.cfg.HTTPTimeout)
		defer cancel()
		return scrape.ScrapeForLinks(sctx, d.scrapers.For(first.Scraper), first.StartURL)
	})
	if err != nil {
		d.log.Warn("discovery: scrape группы не удался", "bank_id", first.BankID, "url", first.StartURL, "err", err)
		return
	}

	categories := make([]model.Category, 0, len(group))
	hints := make(map[model.Category]string, len(group))
	for _, in := range group {
		categories = append(categories, in.Category)
		hints[in.Category] = hintText(in)
	}

	ext, err := retryutil.Do(ctx, func() (*extract.LinksExtraction, error) {
		actx, cancel := context.WithTimeout(ctx, d.cfg.AITimeout)
		defer cancel()
		return d.links.ExtractLinks(actx, markdown, categories, hints)
	})
	if err != nil {
		d.log.Warn("discovery: extract-links группы не удался", "bank_id", first.BankID, "url", first.StartURL, "err", err)
		return
	}

	byCategory := make(map[model.Category][]string)
	for _, l := range ext.Result.Links {
		byCategory[l.Category] = append(byCategory[l.Category], l.URL)
	}

	for _, in := range group {
		urls := resolveAndFilter(first.StartURL, byCategory[in.Category])
		inserted, updated := 0, 0
		for _, u := range urls {
			ins, err := d.st.UpsertSourceURL(ctx, in.BankID, in.Category, u, in.Scraper)
			if err != nil {
				d.log.Warn("discovery: upsert источника не удался (группа)", "instruction_id", in.ID, "url", u, "err", err)
				continue
			}
			if ins {
				inserted++
			} else {
				updated++
			}
		}
		if err := d.st.TouchInstruction(ctx, in.ID, startedAt); err != nil {
			d.log.Warn("discovery: touch last_run_at не удался (группа)", "instruction_id", in.ID, "err", err)
		}
		d.log.Info("инструкция обработана (группа)",
			"instruction_id", in.ID, "bank_id", in.BankID, "category", in.Category,
			"found", len(urls), "inserted", inserted, "updated", updated)
	}
}

// registerStaticSources обрабатывает bank_parse_instructions(kind='static_source'):
// URL уже точно известен, AI на этом шаге не участвует вообще — просто
// регистрируем источник в bank_source_urls с notes+extract_mode='static_source'.
// Сам парсинг (лёгким экстрактором) — задача cmd/parser (см. parser.go).
func (d *Discoverer) registerStaticSources(ctx context.Context) error {
	instrs, err := d.st.StaticSourceInstructions(ctx)
	if err != nil {
		return err
	}
	if len(d.cfg.BankIDs) > 0 {
		instrs = filterInstructionsByBank(instrs, d.cfg.BankIDs)
	}
	for _, in := range instrs {
		startedAt := time.Now()
		notes := ""
		if in.Notes != nil {
			notes = *in.Notes
		}
		ins, err := d.st.UpsertStaticSource(ctx, in.BankID, in.Category, in.StartURL, in.Scraper, notes)
		if err != nil {
			d.log.Warn("discovery: upsert static_source не удался", "instruction_id", in.ID, "url", in.StartURL, "err", err)
			continue
		}
		if err := d.st.TouchInstruction(ctx, in.ID, startedAt); err != nil {
			d.log.Warn("discovery: touch last_run_at не удался (static_source)", "instruction_id", in.ID, "err", err)
		}
		d.log.Info("static_source зарегистрирован", "instruction_id", in.ID, "bank_id", in.BankID, "category", in.Category, "url", in.StartURL, "inserted", ins)
	}
	return nil
}

// maxSequentialID — safety-предел перебора id (НЕ бизнес-лимит: реальный
// стоп — 2 промаха подряд, см. processSequentialIDs). Просто защита от
// зависшего цикла, если у конкретного банка эвристика "промаха" почему-то
// никогда не сработает (напр. сайт лёг и стал отдавать одну и ту же ошибку
// на КАЖДЫЙ id, включая валидные, — тогда после 2 промахов подряд мы бы и
// так остановились рано, лимит просто перестраховка сверху).
const maxSequentialID = 40

// processSequentialIDInstructions обрабатывает bank_parse_instructions
// (kind='sequential_ids'): банк держит детальные страницы продуктов на
// числовых id ({start_url}/1, /2, ...) без каталога-со-ссылками. Настоящего
// HTTP 404 на несуществующем id может не быть вообще (SPA всегда отдаёт 200
// с одной и той же пустой обёрткой — проверено на cbt.tj) — поэтому "промах"
// определяется не по коду ответа, а по содержимому: пробуем заведомо
// невалидный id=0 (самокалибровка, без хардкода языковых маркеров конкретного
// сайта), дальше id=1..maxSequentialID, содержимое, идентичное пробнику, —
// промах. 2 промаха подряд — стоп (аналог "2 подряд 404" из задачи).
func (d *Discoverer) processSequentialIDInstructions(ctx context.Context) {
	instrs, err := d.st.SequentialIDInstructions(ctx)
	if err != nil {
		d.log.Warn("discovery: чтение инструкций sequential_ids не удалось", "err", err)
		return
	}
	if len(d.cfg.BankIDs) > 0 {
		instrs = filterInstructionsByBank(instrs, d.cfg.BankIDs)
	}
	for _, in := range instrs {
		d.processSequentialIDs(ctx, in)
	}
}

func (d *Discoverer) processSequentialIDs(ctx context.Context, in model.DiscoveryInstruction) {
	startedAt := time.Now()
	base := strings.TrimRight(in.StartURL, "/")
	scraper := d.scrapers.For(in.Scraper)

	emptySignature, err := retryutil.Do(ctx, func() (string, error) {
		sctx, cancel := context.WithTimeout(ctx, d.cfg.HTTPTimeout)
		defer cancel()
		return scraper.Scrape(sctx, base+"/0")
	})
	if err != nil {
		d.log.Warn("discovery: sequential_ids не удалось откалибровать пустой ответ (id=0)", "instruction_id", in.ID, "url", base, "err", err)
		return
	}
	emptySignature = strings.TrimSpace(emptySignature)

	inserted, updated, misses := 0, 0, 0
	for id := 1; id <= maxSequentialID; id++ {
		url := base + "/" + strconv.Itoa(id)
		text, err := retryutil.Do(ctx, func() (string, error) {
			sctx, cancel := context.WithTimeout(ctx, d.cfg.HTTPTimeout)
			defer cancel()
			return scraper.Scrape(sctx, url)
		})
		if err != nil || strings.TrimSpace(text) == emptySignature {
			misses++
			if misses >= 2 {
				break
			}
			continue
		}
		misses = 0

		ins, err := d.st.UpsertSourceURL(ctx, in.BankID, in.Category, url, in.Scraper)
		if err != nil {
			d.log.Warn("discovery: upsert источника не удался (sequential_ids)", "instruction_id", in.ID, "url", url, "err", err)
			continue
		}
		if ins {
			inserted++
		} else {
			updated++
		}
	}

	if err := d.st.TouchInstruction(ctx, in.ID, startedAt); err != nil {
		d.log.Warn("discovery: touch last_run_at не удался (sequential_ids)", "instruction_id", in.ID, "err", err)
	}
	d.log.Info("инструкция обработана (sequential_ids)",
		"instruction_id", in.ID, "bank_id", in.BankID, "category", in.Category,
		"inserted", inserted, "updated", updated)
}

// resolveAndFilter резолвит относительные ссылки относительно стартовой,
// оставляет только тот же зарегистрированный домен, убирает дубли и режет лимит.
func resolveAndFilter(start string, links []string) []string {
	base, baseErr := url.Parse(start)
	seen := make(map[string]bool)
	out := make([]string, 0, len(links))

	for _, raw := range links {
		s := strings.TrimSpace(raw)
		if s == "" {
			continue
		}
		abs := s
		if ref, err := url.Parse(s); err == nil && baseErr == nil {
			abs = base.ResolveReference(ref).String()
		}
		if !sameSite(start, abs) {
			continue
		}
		key := normalizeURL(abs)
		if seen[key] {
			continue
		}
		seen[key] = true
		out = append(out, abs)
		if len(out) >= maxLinksPerInstruction {
			break
		}
	}
	return out
}

// sameSite сравнивает зарегистрированные домены (последние два лейбла),
// поэтому поддомены одного банка считаются «своими».
func sameSite(a, b string) bool {
	ua, err1 := url.Parse(a)
	ub, err2 := url.Parse(b)
	if err1 != nil || err2 != nil {
		return false
	}
	return regDomain(ua.Hostname()) == regDomain(ub.Hostname())
}

func regDomain(h string) string {
	parts := strings.Split(strings.ToLower(h), ".")
	if len(parts) < 2 {
		return strings.ToLower(h)
	}
	return parts[len(parts)-2] + "." + parts[len(parts)-1]
}

// normalizeURL приводит URL к канонической форме только для дедупа в рамках
// одного прогона (без fragment/tracking-параметров/хвостового слэша, host в
// нижнем регистре) — почти-дубли ссылок на один и тот же продукт не должны
// плодить отдельные bank_source_urls (см. жалобу на дубли продуктов в рамках банка).
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
