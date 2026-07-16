// Array-split режим: источник — JSON-ответ, который ЦЕЛИКОМ является
// массивом продуктов (или содержит его под известным ключом) — SSB, ICB,
// Арванд. Раньше весь массив (10+ продуктов, иногда с билингва-мёрджем)
// уходил в AI ОДНИМ вызовом — на больших каталогах упирались в потолок
// вывода модели (DeepSeek: 8192 токенов, ответ обрывался на середине JSON,
// см. диагностику SSB-credit). Здесь — на КАЖДЫЙ элемент массива отдельный
// маленький AI-вызов: вход/выход в разы меньше, тот же потолок не страшен,
// плюс качество экстракции обычно выше на одном продукте, чем на десяти сразу.
package parser

import (
	"context"
	"encoding/json"
	"fmt"
	"sync"

	"sravni/parser/internal/extract"
	"sravni/parser/internal/jsonpath"
	"sravni/parser/internal/model"
)

// maxArrayElements ограничивает число элементов на один array-split источник
// (защита от неожиданно огромного каталога — не по одному AI-вызову на тысячу строк).
const maxArrayElements = 100

// runArraySplit скрейпит task.URL как JSON, достаёт массив продуктов по
// task.ArrayPath и запускает по одному AI-вызову на элемент (параллельно,
// с тем же лимитом p.cfg.Concurrency, что и остальной пайплайн). Возвращает
// собранные продукты и сырой ru-ответ (для parser_runs.input_markdown).
//
// Билингва внутри array-split — по типу LangURLRule:
//   - header (ICB: одна и та же запись под ru/tj, связаны по id) — доп. фетч
//     tj-версии тем же URL с другим заголовком, парование элементов по id,
//     смёрдженные пары идут в AI одним текстом на элемент.
//   - query_param/path_replace (SSB: ru/tj — РАЗНЫЕ несвязанные списки,
//     доказано ранее) — пары на уровне элемента ненадёжны, билингва НЕ
//     пытаемся: только ru, name_tg останется null (честно).
//   - nil (Арванд: поля уже билингвальны в каждом элементе) — ничего доп.
//     делать не нужно, AI сам прочитает title_ru/title_tg из одного элемента.
func (p *Parser) runArraySplit(ctx context.Context, task model.SourceTask) ([]productSource, string, error) {
	arrayPath := ""
	if task.ArrayPath != nil {
		arrayPath = *task.ArrayPath
	}

	primaryRaw, err := fetchRaw(ctx, p.httpClient, task.URL)
	if err != nil {
		return nil, "", err
	}

	arr, err := extractArray(primaryRaw, arrayPath)
	if err != nil {
		return nil, primaryRaw, fmt.Errorf("array-split: %w", err)
	}
	if len(arr) > maxArrayElements {
		p.log.Warn("array-split: обрезаю каталог по лимиту", "task_id", task.ID, "found", len(arr), "limit", maxArrayElements)
		arr = arr[:maxArrayElements]
	}
	p.log.Info("array-split: массив получен", "task_id", task.ID, "url", task.URL, "elements", len(arr))

	var tjByID map[string]any
	if task.LangURLRule != nil && task.LangURLRule.Type == "header" {
		tjByID = p.fetchHeaderArrayByID(ctx, task, arrayPath)
	}

	var (
		mu  sync.Mutex
		out []productSource
		wg  sync.WaitGroup
	)
	sem := make(chan struct{}, p.cfg.Concurrency)

	for _, elem := range arr {
		elem := elem
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()

			text := elementText(elem, tjByID)
			ext, err := retry(ctx, func() (*extract.Extraction, error) {
				actx, cancel := context.WithTimeout(ctx, p.cfg.AITimeout)
				defer cancel()
				return p.ai.Extract(actx, text, task.Category)
			})
			if err != nil {
				p.log.Warn("array-split: extract элемента не удался", "task_id", task.ID, "err", err)
				return
			}
			products := toProductSources(ext.Result.Products, task.URL)

			mu.Lock()
			out = append(out, products...)
			mu.Unlock()
		}()
	}
	wg.Wait()
	return out, primaryRaw, nil
}

// fetchHeaderArrayByID доскрейпливает tj-версию ТОГО ЖЕ url (другой заголовок)
// и индексирует её элементы по полю "id" — для парования с ru-элементами.
// Ошибка/несовпадение формата — не фатально, просто нет билингвы (nil).
func (p *Parser) fetchHeaderArrayByID(ctx context.Context, task model.SourceTask, arrayPath string) map[string]any {
	header, hasHeader := task.LangURLRule.Params["header"]
	tjVal, hasTJ := task.LangURLRule.Params["tj"]
	if !hasHeader || !hasTJ || header == "" {
		return nil
	}

	tjRaw, err := retry(ctx, func() (string, error) {
		sctx, cancel := context.WithTimeout(ctx, p.cfg.HTTPTimeout)
		defer cancel()
		return fetchWithHeader(sctx, p.httpClient, task.URL, header, tjVal)
	})
	if err != nil {
		p.log.Warn("array-split: header-фетч tj-версии не удался", "task_id", task.ID, "url", task.URL, "err", err)
		return nil
	}

	arr, err := extractArray(tjRaw, arrayPath)
	if err != nil {
		p.log.Warn("array-split: tj-массив не разобрался", "task_id", task.ID, "err", err)
		return nil
	}

	byID := make(map[string]any, len(arr))
	for _, e := range arr {
		m, ok := e.(map[string]any)
		if !ok {
			continue
		}
		if id, exists := m["id"]; exists {
			byID[fmt.Sprint(id)] = e
		}
	}
	return byID
}

// extractArray разбирает raw как JSON и достаёт массив по arrayPath
// (jsonpath.Resolve; "" — сам ответ уже массив).
func extractArray(raw, arrayPath string) ([]any, error) {
	var data any
	if err := json.Unmarshal([]byte(raw), &data); err != nil {
		return nil, fmt.Errorf("unmarshal: %w", err)
	}
	v, ok := jsonpath.Resolve(data, arrayPath)
	if !ok {
		return nil, fmt.Errorf("путь %q не резолвится", arrayPath)
	}
	arr, ok := v.([]any)
	if !ok {
		return nil, fmt.Errorf("по пути %q не массив", arrayPath)
	}
	return arr, nil
}

// elementText — текст ОДНОГО элемента для AI: сам элемент как JSON, плюс
// смёрдженная tj-пара (по id), если она нашлась в tjByID.
func elementText(elem any, tjByID map[string]any) string {
	ruJSON, _ := json.Marshal(elem)
	if tjByID == nil {
		return string(ruJSON)
	}
	m, ok := elem.(map[string]any)
	if !ok {
		return string(ruJSON)
	}
	id, exists := m["id"]
	if !exists {
		return string(ruJSON)
	}
	tjElem, found := tjByID[fmt.Sprint(id)]
	if !found {
		return string(ruJSON)
	}
	tjJSON, _ := json.Marshal(tjElem)
	return mergeBilingual(string(ruJSON), string(tjJSON))
}
