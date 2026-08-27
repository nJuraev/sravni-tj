// Command discover — discovery-парсер Sravni.tj.
//
// Один прогон: читает bank_parse_instructions(kind='product_discovery'),
// обходит стартовые страницы банков, находит ссылки на страницы продуктов
// и наполняет bank_source_urls. Периодичность (раз/неделю) — внешний крон.
//
// Код выхода: 0 — прогон выполнен (частичные провалы инструкций не фатальны);
// ненулевой — фатальный сбой (нет конфига/БД).
package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	"sravni/parser/internal/config"
	"sravni/parser/internal/discover"
	"sravni/parser/internal/extract"
	"sravni/parser/internal/scrape"
	"sravni/parser/internal/store"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	if err := run(log); err != nil {
		log.Error("фатальная ошибка discovery", "err", err)
		os.Exit(1)
	}
}

func run(log *slog.Logger) error {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	cfg, err := config.Load()
	if err != nil {
		return err
	}

	dbCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	st, err := store.NewPG(dbCtx, cfg.DatabaseURL)
	if err != nil {
		return err
	}
	defer st.Close()

	httpClient := scrape.NewHTTPClient()

	scrapers, err := scrape.New(cfg, httpClient)
	if err != nil {
		return err
	}
	ai, err := extract.New(cfg, httpClient)
	if err != nil {
		return err
	}
	// LinksExtractor не поддержан всеми провайдерами (см. extract.NewLinks,
	// тот же прецедент, что и NewRates) — это НЕ фатально для discovery в
	// целом: банки с общей start_url для credit/deposit (единственные, кому
	// он нужен) просто идут обычным одиночным путём, как до объединения.
	links, err := extract.NewLinks(cfg, httpClient)
	if err != nil {
		log.Warn("объединённый discovery для банков с общей start_url недоступен", "err", err)
		links = nil
	}

	d := discover.New(cfg, st, scrapers, ai, links, log)
	return d.Run(ctx)
}
