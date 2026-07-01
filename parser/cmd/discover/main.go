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
	"net/http"
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

	httpClient := &http.Client{Transport: http.DefaultTransport}

	scraper, err := scrape.New(cfg, httpClient)
	if err != nil {
		return err
	}
	ai, err := extract.New(cfg, httpClient)
	if err != nil {
		return err
	}

	d := discover.New(cfg, st, scraper, ai, log)
	return d.Run(ctx)
}
