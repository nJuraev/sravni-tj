package store

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"sravni/parser/internal/model"
)

// PG — реализация Store поверх pgxpool.
type PG struct {
	pool *pgxpool.Pool
}

// NewPG создаёт пул соединений и проверяет связь с БД.
func NewPG(ctx context.Context, dsn string) (*PG, error) {
	pool, err := pgxpool.New(ctx, dsn)
	if err != nil {
		return nil, fmt.Errorf("store: создание пула: %w", err)
	}
	if err := pool.Ping(ctx); err != nil {
		pool.Close()
		return nil, fmt.Errorf("store: ping БД: %w", err)
	}
	return &PG{pool: pool}, nil
}

// Close освобождает пул.
func (s *PG) Close() {
	if s.pool != nil {
		s.pool.Close()
	}
}

// ActiveTasks читает активные источники парсинга.
func (s *PG) ActiveTasks(ctx context.Context) ([]model.SourceTask, error) {
	const q = `
		SELECT id, bank_id, category, url
		FROM bank_source_urls
		WHERE is_active = true
		ORDER BY id`
	rows, err := s.pool.Query(ctx, q)
	if err != nil {
		return nil, fmt.Errorf("store: выборка задач: %w", err)
	}
	defer rows.Close()

	var tasks []model.SourceTask
	for rows.Next() {
		var t model.SourceTask
		var cat string
		if err := rows.Scan(&t.ID, &t.BankID, &cat, &t.URL); err != nil {
			return nil, fmt.Errorf("store: scan задачи: %w", err)
		}
		t.Category = model.Category(cat)
		tasks = append(tasks, t)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("store: чтение задач: %w", err)
	}
	return tasks, nil
}

// DiscoveryInstructions читает активные инструкции discovery.
func (s *PG) DiscoveryInstructions(ctx context.Context) ([]model.DiscoveryInstruction, error) {
	const q = `
		SELECT id, bank_id, category, start_url, menu_sections, notes
		FROM bank_parse_instructions
		WHERE kind = 'product_discovery' AND is_active = true
		ORDER BY id`
	rows, err := s.pool.Query(ctx, q)
	if err != nil {
		return nil, fmt.Errorf("store: выборка инструкций discovery: %w", err)
	}
	defer rows.Close()

	var out []model.DiscoveryInstruction
	for rows.Next() {
		var (
			in       model.DiscoveryInstruction
			cat      string
			sections []byte // jsonb массив строк или NULL
		)
		if err := rows.Scan(&in.ID, &in.BankID, &cat, &in.StartURL, &sections, &in.Notes); err != nil {
			return nil, fmt.Errorf("store: scan инструкции: %w", err)
		}
		in.Category = model.Category(cat)
		if len(sections) > 0 {
			// Игнорируем ошибку декода: подсказки не критичны для обхода.
			_ = json.Unmarshal(sections, &in.MenuSections)
		}
		out = append(out, in)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("store: чтение инструкций: %w", err)
	}
	return out, nil
}

// UpsertSourceURL добавляет/реактивирует источник по уникальному url.
// (xmax = 0) различает вставку (true) от обновления существующей строки (false).
func (s *PG) UpsertSourceURL(ctx context.Context, bankID int64, category model.Category, url string) (bool, error) {
	const q = `
		INSERT INTO bank_source_urls (bank_id, category, url, is_active, created_at, updated_at)
		VALUES ($1, $2, $3, true, now(), now())
		ON CONFLICT (url) DO UPDATE SET
			is_active  = true,
			updated_at = now()
		RETURNING (xmax = 0) AS inserted`
	var inserted bool
	if err := s.pool.QueryRow(ctx, q, bankID, string(category), url).Scan(&inserted); err != nil {
		return false, fmt.Errorf("store: upsert источника: %w", err)
	}
	return inserted, nil
}

// TouchInstruction обновляет last_run_at инструкции discovery.
func (s *PG) TouchInstruction(ctx context.Context, instructionID int64, at time.Time) error {
	const q = `UPDATE bank_parse_instructions SET last_run_at = $2, updated_at = now() WHERE id = $1`
	if _, err := s.pool.Exec(ctx, q, instructionID, at); err != nil {
		return fmt.Errorf("store: touch last_run_at: %w", err)
	}
	return nil
}

// RatesInstructions читает активные инструкции курсов (kind='rates').
func (s *PG) RatesInstructions(ctx context.Context) ([]model.DiscoveryInstruction, error) {
	const q = `
		SELECT id, bank_id, start_url, notes
		FROM bank_parse_instructions
		WHERE kind = 'rates' AND is_active = true
		ORDER BY id`
	rows, err := s.pool.Query(ctx, q)
	if err != nil {
		return nil, fmt.Errorf("store: выборка инструкций курсов: %w", err)
	}
	defer rows.Close()

	var out []model.DiscoveryInstruction
	for rows.Next() {
		var in model.DiscoveryInstruction
		if err := rows.Scan(&in.ID, &in.BankID, &in.StartURL, &in.Notes); err != nil {
			return nil, fmt.Errorf("store: scan инструкции курсов: %w", err)
		}
		out = append(out, in)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("store: чтение инструкций курсов: %w", err)
	}
	return out, nil
}

// UpsertRate идемпотентно пишет курс по (bank_id, currency, category, rate_date).
func (s *PG) UpsertRate(ctx context.Context, rw RateWrite) error {
	const q = `
		INSERT INTO bank_currency_rates
			(bank_id, currency, category, buy, sell, rate_date, parsed_at, created_at, updated_at)
		VALUES ($1, $2, $3, $4, $5, $6, $7, now(), now())
		ON CONFLICT (bank_id, currency, category, rate_date) DO UPDATE SET
			buy        = EXCLUDED.buy,
			sell       = EXCLUDED.sell,
			parsed_at  = EXCLUDED.parsed_at,
			updated_at = now()`
	_, err := s.pool.Exec(ctx, q,
		rw.BankID, rw.Currency, rw.Category, rw.Buy, rw.Sell, rw.RateDate, rw.ParsedAt,
	)
	if err != nil {
		return fmt.Errorf("store: upsert курса: %w", err)
	}
	return nil
}

// UpsertProduct: транзакционный идемпотентный upsert продукта + replace-all сетки
// + пересчёт агрегатов rate_min/rate_max из сетки (schema.md §9).
func (s *PG) UpsertProduct(ctx context.Context, pw ProductWrite) (int64, error) {
	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return 0, fmt.Errorf("store: begin tx: %w", err)
	}
	// Rollback безопасен после Commit (pgx вернёт ErrTxClosed, игнорируем).
	defer tx.Rollback(ctx)

	p := pw.Product
	featuresJSON, err := json.Marshal(p.Features)
	if err != nil {
		return 0, fmt.Errorf("store: marshal features: %w", err)
	}

	// 1) UPSERT по (source_url_id, external_key).
	//    status НЕ перетирается на update — уважаем администраторский статус (§8.2).
	//    При INSERT status = 'draft' (утверждённое решение: парсер вставляет как draft).
	//
	//    Приоритет администратора (locked_fields): поля, которые редактор задал
	//    через админку, помечаются в products.locked_fields (jsonb-массив имён).
	//    Парсер их НЕ перетирает:
	//      - category/subcategory: если поле залочено — оставляем значение из БД;
	//      - features (метки): всегда объединяем (union), новые метки добавляются;
    //        при залоченных features значения администратора имеют приоритет
	//        (EXCLUDED || products → правый операнд побеждает), иначе свежий
	//        результат парсера побеждает, но ранее известные метки не теряются.
	const upsertSQL = `
		INSERT INTO products
			(bank_id, source_url_id, external_key, category, name_ru, name_tg,
			 description_ru, description_tg, status, currency,
			 rate_min, rate_max, amount_min, amount_max, term_min, term_max,
			 features, parsed_at, subcategory, created_at, updated_at)
		VALUES
			($1, $2, $3, $4, $5, $6,
			 $7, $8, 'draft', $9,
			 $10, $11, $12, $13, $14, $15,
			 $16, $17, $18, now(), now())
		ON CONFLICT (source_url_id, external_key) DO UPDATE SET
			bank_id        = EXCLUDED.bank_id,
			category       = CASE WHEN products.locked_fields @> '"category"'::jsonb
			                      THEN products.category ELSE EXCLUDED.category END,
			subcategory    = CASE WHEN products.locked_fields @> '"subcategory"'::jsonb
			                      THEN products.subcategory ELSE EXCLUDED.subcategory END,
			features       = CASE WHEN products.locked_fields @> '"features"'::jsonb
			                      THEN COALESCE(EXCLUDED.features, '{}'::jsonb) || COALESCE(products.features, '{}'::jsonb)
			                      ELSE COALESCE(products.features, '{}'::jsonb) || COALESCE(EXCLUDED.features, '{}'::jsonb) END,
			name_ru        = EXCLUDED.name_ru,
			name_tg        = EXCLUDED.name_tg,
			description_ru = EXCLUDED.description_ru,
			description_tg = EXCLUDED.description_tg,
			currency       = EXCLUDED.currency,
			rate_min       = EXCLUDED.rate_min,
			rate_max       = EXCLUDED.rate_max,
			amount_min     = EXCLUDED.amount_min,
			amount_max     = EXCLUDED.amount_max,
			term_min       = EXCLUDED.term_min,
			term_max       = EXCLUDED.term_max,
			parsed_at      = EXCLUDED.parsed_at,
			updated_at     = now()
		RETURNING id`

	var productID int64
	err = tx.QueryRow(ctx, upsertSQL,
		pw.BankID, pw.SourceURLID, pw.ExternalKey, string(p.Category),
		p.NameRU, p.NameTG, p.DescriptionRU, p.DescriptionTG, string(p.Currency),
		p.RateMin, p.RateMax, p.AmountMin, p.AmountMax, p.TermMin, p.TermMax,
		featuresJSON, pw.ParsedAt, p.Subcategory,
	).Scan(&productID)
	if err != nil {
		return 0, fmt.Errorf("store: upsert продукта: %w", err)
	}

	// 2) Replace-all тарифной сетки (источник истины — rate_tiers AI).
	if _, err := tx.Exec(ctx, `DELETE FROM product_rates WHERE product_id = $1`, productID); err != nil {
		return 0, fmt.Errorf("store: удаление старой сетки: %w", err)
	}

	if len(p.RateTiers) > 0 {
		// Пакетная вставка тиров через CopyFrom-эквивалент — здесь обычный INSERT
		// в цикле внутри транзакции (объёмы тиров малы, maxItems=50).
		const tierSQL = `
			INSERT INTO product_rates
				(product_id, term_min, term_max, amount_min, amount_max, rate, created_at)
			VALUES ($1, $2, $3, $4, $5, $6, now())`
		for _, t := range p.RateTiers {
			if _, err := tx.Exec(ctx, tierSQL,
				productID, t.TermMin, t.TermMax, t.AmountMin, t.AmountMax, t.Rate,
			); err != nil {
				return 0, fmt.Errorf("store: вставка тира: %w", err)
			}
		}

		// 3) Пересчёт денормализованных агрегатов из вставленной сетки.
		const aggSQL = `
			UPDATE products SET
				rate_min = (SELECT MIN(rate) FROM product_rates WHERE product_id = $1),
				rate_max = (SELECT MAX(rate) FROM product_rates WHERE product_id = $1)
			WHERE id = $1`
		if _, err := tx.Exec(ctx, aggSQL, productID); err != nil {
			return 0, fmt.Errorf("store: пересчёт агрегатов: %w", err)
		}
	}

	if err := tx.Commit(ctx); err != nil {
		return 0, fmt.Errorf("store: commit: %w", err)
	}
	return productID, nil
}

// MarkOutdated переводит исчезнувшие продукты источника в 'outdated' (§8.3).
// Затрагивает только сейчас активные ('active'); вручную скрытые (hidden/draft)
// не трогаются. Продукты текущего прогона имеют parsed_at >= runStartedAt.
func (s *PG) MarkOutdated(ctx context.Context, sourceURLID int64, runStartedAt time.Time) (int64, error) {
	const q = `
		UPDATE products
		SET status = 'outdated', updated_at = now()
		WHERE source_url_id = $1
		  AND status = 'active'
		  AND (parsed_at IS NULL OR parsed_at < $2)`
	tag, err := s.pool.Exec(ctx, q, sourceURLID, runStartedAt)
	if err != nil {
		return 0, fmt.Errorf("store: mark outdated: %w", err)
	}
	return tag.RowsAffected(), nil
}

// LogRun пишет метаданные запуска в parser_runs.
func (s *PG) LogRun(ctx context.Context, rl RunLog) error {
	duration := int(rl.FinishedAt.Sub(rl.StartedAt).Milliseconds())

	// Пустые строки → NULL в БД.
	var aiRaw, errMsg, inputMD *string
	if rl.AIRawResponse != "" {
		aiRaw = &rl.AIRawResponse
	}
	if rl.ErrorMessage != "" {
		errMsg = &rl.ErrorMessage
	}
	if rl.InputMarkdown != "" {
		inputMD = &rl.InputMarkdown
	}

	const q = `
		INSERT INTO parser_runs
			(bank_source_url_id, started_at, finished_at, duration_ms,
			 status, ai_raw_response, input_markdown, error_message, products_upserted)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)`
	_, err := s.pool.Exec(ctx, q,
		rl.BankSourceURLID, rl.StartedAt, rl.FinishedAt, duration,
		string(rl.Status), aiRaw, inputMD, errMsg, rl.ProductsUpserted,
	)
	if err != nil {
		return fmt.Errorf("store: запись parser_runs: %w", err)
	}
	return nil
}

// TouchSourceParsed обновляет last_parsed_at источника.
func (s *PG) TouchSourceParsed(ctx context.Context, sourceURLID int64, at time.Time) error {
	const q = `UPDATE bank_source_urls SET last_parsed_at = $2, updated_at = now() WHERE id = $1`
	if _, err := s.pool.Exec(ctx, q, sourceURLID, at); err != nil {
		return fmt.Errorf("store: touch last_parsed_at: %w", err)
	}
	return nil
}

// Гарантия на этапе компиляции, что PG реализует Store.
var _ Store = (*PG)(nil)
