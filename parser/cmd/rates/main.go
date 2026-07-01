// Command rates — парсер курсов валют Sravni.tj.
//
// Один прогон: читает bank_parse_instructions(kind='rates'), скрейпит страницы
// курсов банков (Jina; вкладки в статичном HTML), извлекает buy/sell по валютам
// и категориям (cash/transfer) и пишет в bank_currency_rates.
// Периодичность (раз/неделю или чаще) — внешний крон.
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
	"sravni/parser/internal/extract"
	"sravni/parser/internal/rates"
	"sravni/parser/internal/scrape"
	"sravni/parser/internal/store"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	if err := run(log); err != nil {
		log.Error("фатальная ошибка парсинга курсов", "err", err)
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
	ai, err := extract.NewRates(cfg, httpClient)
	if err != nil {
		return err
	}

	r := rates.New(cfg, st, scraper, ai, log)
	return r.Run(ctx)
}
