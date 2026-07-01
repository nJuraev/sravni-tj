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
	"strings"
	"sync"
	"time"

	"sravni/parser/internal/config"
	"sravni/parser/internal/extract"
	"sravni/parser/internal/model"
	"sravni/parser/internal/scrape"
	"sravni/parser/internal/store"
)

// maxLinksPerInstruction ограничивает число URL с одной стартовой страницы
// (защита от мусорных меню/футеров с сотнями ссылок).
const maxLinksPerInstruction = 60

// Discoverer связывает зависимости discovery-пайплайна.
type Discoverer struct {
	cfg     *config.Config
	st      store.Store
	scraper scrape.Scraper
	ai      extract.AIExtractor
	log     *slog.Logger
}

// New создаёт discovery-оркестратор.
func New(cfg *config.Config, st store.Store, scraper scrape.Scraper, ai extract.AIExtractor, log *slog.Logger) *Discoverer {
	return &Discoverer{cfg: cfg, st: st, scraper: scraper, ai: ai, log: log}
}

// Run обрабатывает все активные инструкции discovery (с учётом Concurrency).
// Фатальная ошибка — только если не удалось прочитать инструкции.
func (d *Discoverer) Run(ctx context.Context) error {
	instrs, err := d.st.DiscoveryInstructions(ctx)
	if err != nil {
		return err
	}
	d.log.Info("старт discovery", "instructions", len(instrs), "concurrency", d.cfg.Concurrency)

	sem := make(chan struct{}, d.cfg.Concurrency)
	var wg sync.WaitGroup

	for _, in := range instrs {
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
	wg.Wait()
	d.log.Info("discovery завершён")
	return nil
}

// process обрабатывает одну инструкцию: scrape → extract links → upsert sources.
func (d *Discoverer) process(ctx context.Context, in model.DiscoveryInstruction) {
	startedAt := time.Now()

	markdown, err := func() (string, error) {
		sctx, cancel := context.WithTimeout(ctx, d.cfg.HTTPTimeout)
		defer cancel()
		return d.scraper.Scrape(sctx, in.StartURL)
	}()
	if err != nil {
		d.log.Warn("discovery: scrape стартовой не удался", "instruction_id", in.ID, "url", in.StartURL, "err", err)
		return
	}
	markdown = prependHints(markdown, in)

	ext, err := func() (*extract.Extraction, error) {
		actx, cancel := context.WithTimeout(ctx, d.cfg.AITimeout)
		defer cancel()
		return d.ai.Extract(actx, markdown, in.Category)
	}()
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
		ins, err := d.st.UpsertSourceURL(ctx, in.BankID, in.Category, u)
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
	if b.Len() == 0 {
		return markdown
	}
	return b.String() + "\n" + markdown
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
		if seen[abs] {
			continue
		}
		seen[abs] = true
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
