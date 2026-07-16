// Command parser — точка входа парсера банковских продуктов Sravni.tj.
//
// Выполняет ОДИН прогон (читает активные источники, обрабатывает, пишет в БД)
// и завершается. Периодичность обеспечивается внешним кроном (specs/parser.md §1).
//
// Код выхода: 0 — прогон выполнен (даже при частичных провалах задач, §7.3);
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
	"sravni/parser/internal/extract"
	"sravni/parser/internal/parser"
	"sravni/parser/internal/scrape"
	"sravni/parser/internal/store"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	if err := run(log); err != nil {
		log.Error("фатальная ошибка прогона", "err", err)
		os.Exit(1)
	}
}

func run(log *slog.Logger) error {
	// Грейсфул-отмена по SIGINT/SIGTERM.
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	cfg, err := config.Load()
	if err != nil {
		return err
	}

	// Подключение к БД (фатально при сбое — процесс не может работать).
	dbCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	st, err := store.NewPG(dbCtx, cfg.DatabaseURL)
	if err != nil {
		return err
	}
	defer st.Close()

	// Общий HTTP-клиент (TLS AIA-фолбэк — см. scrape.NewHTTPClient).
	httpClient := scrape.NewHTTPClient()

	scrapers, err := scrape.New(cfg, httpClient)
	if err != nil {
		return err
	}
	ai, err := extract.New(cfg, httpClient)
	if err != nil {
		return err
	}

	p := parser.New(cfg, st, scrapers, ai, httpClient, log)
	return p.Run(ctx)
}
