// Package rates — пайплайн парсинга курсов валют.
//
// По bank_parse_instructions(kind='rates'): scrape страницы курсов (Jina,
// вкладки в статичном HTML) → AI извлекает buy/sell по валютам и категориям
// (cash/transfer) → валидация → upsert bank_currency_rates.
package rates

import (
	"context"
	"log/slog"
	"regexp"
	"strings"
	"sync"
	"time"

	"sravni/parser/internal/config"
	"sravni/parser/internal/extract"
	"sravni/parser/internal/model"
	"sravni/parser/internal/scrape"
	"sravni/parser/internal/store"
)

// Rater связывает зависимости пайплайна курсов.
type Rater struct {
	cfg     *config.Config
	st      store.Store
	scraper scrape.Scraper
	ai      extract.RatesExtractor
	log     *slog.Logger
}

// New создаёт оркестратор курсов.
func New(cfg *config.Config, st store.Store, scraper scrape.Scraper, ai extract.RatesExtractor, log *slog.Logger) *Rater {
	return &Rater{cfg: cfg, st: st, scraper: scraper, ai: ai, log: log}
}

// Run обрабатывает все активные инструкции курсов (с учётом Concurrency).
func (r *Rater) Run(ctx context.Context) error {
	instrs, err := r.st.RatesInstructions(ctx)
	if err != nil {
		return err
	}
	if len(r.cfg.BankIDs) > 0 {
		instrs = filterInstructionsByBank(instrs, r.cfg.BankIDs)
		r.log.Info("фильтр PARSER_BANK_IDS применён", "bank_ids", r.cfg.BankIDs, "instructions", len(instrs))
	}
	r.log.Info("старт парсинга курсов", "instructions", len(instrs), "concurrency", r.cfg.Concurrency)

	sem := make(chan struct{}, r.cfg.Concurrency)
	var wg sync.WaitGroup

	for _, in := range instrs {
		in := in
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			defer func() {
				if rec := recover(); rec != nil {
					r.log.Error("паника в инструкции курсов", "instruction_id", in.ID, "recover", rec)
				}
			}()
			r.process(ctx, in)
		}()
	}
	wg.Wait()
	r.log.Info("парсинг курсов завершён")
	return nil
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

func (r *Rater) process(ctx context.Context, in model.DiscoveryInstruction) {
	startedAt := time.Now()
	notes := ""
	if in.Notes != nil {
		notes = *in.Notes
	}

	// Сырой HTML (с <script>) — курсы у SPA-банков лежат в JSON внутри скриптов,
	// readability их режет. Затем вырезаем окна вокруг ключей курсов, чтобы не
	// слать в AI сотни КБ markup.
	rawHTML, err := func() (string, error) {
		sctx, cancel := context.WithTimeout(ctx, r.cfg.HTTPTimeout)
		defer cancel()
		return r.scraper.ScrapeRaw(sctx, in.StartURL)
	}()
	if err != nil {
		r.log.Warn("rates: scrape страницы курсов не удался", "instruction_id", in.ID, "url", in.StartURL, "err", err)
		return
	}
	reduced := extractRateContext(rawHTML)
	r.log.Info("rates: scrape ok", "instruction_id", in.ID, "raw_chars", len(rawHTML), "reduced_chars", len(reduced))

	ext, err := func() (*extract.RatesExtraction, error) {
		actx, cancel := context.WithTimeout(ctx, r.cfg.AITimeout)
		defer cancel()
		return r.ai.ExtractRates(actx, reduced, notes)
	}()
	if err != nil {
		r.log.Warn("rates: extract не удался", "instruction_id", in.ID, "url", in.StartURL, "err", err)
		return
	}

	saved, skipped := 0, 0
	for _, row := range ext.Result.Rates {
		rw, ok := normalize(in.BankID, row, startedAt)
		if !ok {
			skipped++
			continue
		}
		if err := r.st.UpsertRate(ctx, rw); err != nil {
			r.log.Warn("rates: upsert не удался", "instruction_id", in.ID, "currency", rw.Currency, "err", err)
			skipped++
			continue
		}
		saved++
	}

	if err := r.st.TouchInstruction(ctx, in.ID, startedAt); err != nil {
		r.log.Warn("rates: touch last_run_at не удался", "instruction_id", in.ID, "err", err)
	}

	r.log.Info("инструкция курсов обработана",
		"instruction_id", in.ID, "bank_id", in.BankID, "saved", saved, "skipped", skipped)
}

// rateKeywordRe — сигналы расположения курсов в сыром HTML (таблицы И JSON-в-скриптах).
// «перевод» — вкладка «Денежные переводы» (без неё раньше терялась category=transfer).
var rateKeywordRe = regexp.MustCompile(`(?i)exchangeRates|cashDesks|nonCash|transfers|conversion|"purchase"|"sale"|"buy"|"sell"|курс|харид|фуру|продаж|покупк|перевод|\bUSD\b|\bEUR\b|\bRUB\b`)

// svgRe вырезает инлайн-SVG (иконки флагов валют и т.п.) ДО поиска ключевых
// слов. На страницах курсов банков (например eskhata.com) каждая строка
// валюты в КАЖДОЙ вкладке несёт SVG-иконку на 2-5 тыс. символов — без вырезки
// это декоративное месиво съедает весь rateMaxContext ещё на первой вкладке
// («Частным лицам»), и до вкладки «Денежные переводы» окно не дотягивается
// (баг: парсились только cash-курсы, transfer терялся полностью).
var svgRe = regexp.MustCompile(`(?is)<svg\b[^>]*>.*?</svg>`)

const (
	rateWindowBefore = 200   // символов до ключа
	rateWindowAfter  = 2500  // символов после (JSON-массив курсов влезает)
	rateMaxContext   = 20000 // потолок передаваемого в AI контекста (~6k токенов)
)

// extractRateContext вырезает из сырого HTML окна вокруг ключей курсов и
// склеивает их — чтобы не слать в AI сотни КБ markup. Работает и для таблиц,
// и для JSON внутри <script> (Next.js exchangeRates и т.п.).
func extractRateContext(html string) string {
	html = svgRe.ReplaceAllString(html, "")
	locs := rateKeywordRe.FindAllStringIndex(html, -1)
	if len(locs) == 0 {
		if len(html) > rateMaxContext {
			return html[:rateMaxContext]
		}
		return html
	}

	type rng struct{ s, e int }
	var merged []rng
	for _, l := range locs {
		s := l[0] - rateWindowBefore
		if s < 0 {
			s = 0
		}
		e := l[1] + rateWindowAfter
		if e > len(html) {
			e = len(html)
		}
		if n := len(merged); n > 0 && s <= merged[n-1].e {
			if e > merged[n-1].e {
				merged[n-1].e = e
			}
			continue
		}
		merged = append(merged, rng{s, e})
	}

	var b strings.Builder
	for _, m := range merged {
		if b.Len() >= rateMaxContext {
			break
		}
		seg := html[m.s:m.e]
		if b.Len()+len(seg) > rateMaxContext {
			seg = seg[:rateMaxContext-b.Len()]
		}
		b.WriteString(seg)
		b.WriteString("\n…\n")
	}
	return b.String()
}

// maxPlausibleRate — верхняя граница вменяемого курса (TJS за единицу валюты).
// Отсекает галлюцинации/мусор (например попавшую цену золотого слитка).
const maxPlausibleRate = 1000.0

// normalize валидирует и приводит строку курса к записи. ok=false → пропустить.
func normalize(bankID int64, row model.RateRow, parsedAt time.Time) (store.RateWrite, bool) {
	cur := strings.ToUpper(strings.TrimSpace(row.Currency))
	if len(cur) != 3 {
		return store.RateWrite{}, false
	}
	cat := strings.ToLower(strings.TrimSpace(row.Category))
	if cat != "cash" && cat != "transfer" {
		return store.RateWrite{}, false
	}

	buy := sanitize(row.Buy)
	sell := sanitize(row.Sell)
	if buy == nil && sell == nil {
		return store.RateWrite{}, false // обе стороны пусты/невалидны
	}

	return store.RateWrite{
		BankID:   bankID,
		Currency: cur,
		Category: cat,
		Buy:      buy,
		Sell:     sell,
		RateDate: parsedAt,
		ParsedAt: parsedAt,
	}, true
}

// sanitize отбрасывает неположительные и неправдоподобно большие значения.
func sanitize(v *float64) *float64 {
	if v == nil {
		return nil
	}
	if *v <= 0 || *v > maxPlausibleRate {
		return nil
	}
	return v
}
