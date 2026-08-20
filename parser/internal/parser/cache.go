// Package parser: skip-if-unchanged кэш — не звать AI повторно, если текст,
// который бы ушёл в extract, побайтово (+ версия промпта) совпадает с прошлым
// успешным прогоном (см. план "Часть B"). Index-режима в cmd/parser больше
// нет (discover отдельно собирает ссылки, parser только парсит готовые
// source_urls) — поэтому здесь только два пути: обычный (этот файл) и
// array-split (см. arraysplit.go — свой хэш на каждый элемент массива).
package parser

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"time"

	"sravni/parser/internal/extract"
	"sravni/parser/internal/model"
	"sravni/parser/internal/store"
)

// contentHash — sha256 hex текста, который уходит в AI (markdown или JSON-
// элемент array-split), СМЕШАННОГО с extract.PromptVersion. Версия в хэше —
// если мы поменяли системный промпт/схему (а мы за сегодня это делали
// несколько раз), кэш инвалидируется сам на следующий прогон, а не тихо
// продолжает отдавать продукты, извлечённые старой логикой, пока сайт банка
// сам не поменяется.
func contentHash(s string) string {
	sum := sha256.Sum256([]byte(extract.PromptVersion + "|" + s))
	return hex.EncodeToString(sum[:])
}

// trySkipUnchanged проверяет, можно ли пропустить AI для задачи целиком:
// прошлый успешный (без отбраковки) прогон должен был дать РОВНО один
// products.source_url, совпадающий с task.URL. Пустой набор (ни разу успешно
// не парсили без отбраковки) — не наш случай, парсим как обычно. Несколько
// разных source_url теоретически возможны только как остаток от СТАРЫХ
// прогонов до удаления index-режима — тоже не трогаем, пусть просто устареют.
//
// Возвращает (outcome, true), если задача пропущена. Ошибка похода в БД —
// не фатальна для пайплайна: вызывающий код просто идёт по обычному пути.
func (p *Parser) trySkipUnchanged(ctx context.Context, task model.SourceTask, startedAt time.Time) (taskOutcome, bool, error) {
	urls, err := p.st.DistinctProductSourceURLs(ctx, task.ID)
	if err != nil {
		return taskOutcome{}, false, err
	}
	if len(urls) != 1 || urls[0] != task.URL {
		return taskOutcome{}, false, nil
	}

	n, err := p.st.TouchProductsBySourceURL(ctx, task.ID, task.URL, startedAt)
	if err != nil {
		return taskOutcome{}, false, err
	}
	if err := p.st.TouchSourceParsed(ctx, task.ID, startedAt); err != nil {
		p.log.Warn("не удалось обновить last_parsed_at", "task_id", task.ID, "err", err)
	}
	p.log.Info("задача пропущена: контент не изменился", "task_id", task.ID, "url", task.URL, "touched", n)
	return taskOutcome{status: store.RunSuccess, upserted: int(n)}, true, nil
}
