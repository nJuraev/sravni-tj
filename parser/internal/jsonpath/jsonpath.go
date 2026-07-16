// Package jsonpath — маленький JSON-путь общий для rates (детерминированные
// курсы) и parser (array-split режим): навигация по вложенным map/массивам
// с фильтрами. Вынесен из rates/deterministic.go — используется в обоих
// местах, один источник истины.
package jsonpath

import (
	"fmt"
	"strconv"
	"strings"
)

// Resolve — маленький JSON-путь: точка разделяет сегменты, у сегмента
// опционально есть имя-ключ (map) и/или один и более [...]-селекторов подряд.
// Селектор внутри []:
//   - число N            → индекс массива: arr[N] (map[string]any НЕ трогает).
//   - "field=value[,field2=value2,...]" → первый элемент массива, где ВСЕ
//     условия выполняются. field может быть и именем ключа объекта
//     (map[string]any), и числовым индексом внутри элемента-массива (для
//     позиционных массивов вида [["USD","9.2","9.3"],...]).
//     Значение сравнивается как текст (fmt.Sprint).
//
// path == "" → вернуть data как есть (навигации нет) — используется в
// array-split режиме, когда сам ответ УЖЕ массив продуктов (без обёртки).
//
// Примеры путей:
//
//	"USD_buy"                                   — плоский объект.
//	"data.cash.usd.buy"                         — вложенный объект.
//	"[type_currency=CASH_RATE,currency_name=USD].buy_rate" — плоский массив
//	  тэгированных записей (корень — массив).
//	"[key=cash].data[title=USD].value_buy"      — массив групп, внутри группы
//	  (объект) — вложенный массив, отфильтрованный по title.
//	"data[0=Cash_Rate].1[0=USD].1"              — позиционные тройки.
//	"results"                                    — array-split: массив продуктов
//	  под ключом results (SSB). "" — массив это сам корень (Арванд).
func Resolve(data any, path string) (any, bool) {
	cur := data
	for _, seg := range strings.Split(path, ".") {
		name, brackets, ok := splitSegment(seg)
		if !ok {
			return nil, false
		}
		if name != "" {
			if idx, err := strconv.Atoi(name); err == nil {
				arr, ok := cur.([]any)
				if !ok || idx < 0 || idx >= len(arr) {
					return nil, false
				}
				cur = arr[idx]
			} else {
				m, ok := cur.(map[string]any)
				if !ok {
					return nil, false
				}
				cur, ok = m[name]
				if !ok {
					return nil, false
				}
			}
		}
		for _, b := range brackets {
			cur, ok = applyBracket(cur, b)
			if !ok {
				return nil, false
			}
		}
	}
	return cur, true
}

// splitSegment разбирает сегмент пути "name[a][b]" → ("name", ["a","b"], true).
// Незакрытая "[" — ошибка формата пути (ok=false).
func splitSegment(seg string) (name string, brackets []string, ok bool) {
	name = seg
	for {
		i := strings.IndexByte(name, '[')
		if i < 0 {
			return name, brackets, true
		}
		j := strings.IndexByte(name[i:], ']')
		if j < 0 {
			return "", nil, false
		}
		brackets = append(brackets, name[i+1:i+j])
		name = name[:i] + name[i+j+1:]
	}
}

// applyBracket применяет один [...]-селектор к текущему значению.
func applyBracket(cur any, inner string) (any, bool) {
	if idx, err := strconv.Atoi(inner); err == nil {
		arr, ok := cur.([]any)
		if !ok || idx < 0 || idx >= len(arr) {
			return nil, false
		}
		return arr[idx], true
	}

	var conditions [][2]string
	for _, part := range strings.Split(inner, ",") {
		kv := strings.SplitN(part, "=", 2)
		if len(kv) != 2 {
			return nil, false
		}
		conditions = append(conditions, [2]string{kv[0], kv[1]})
	}

	arr, ok := cur.([]any)
	if !ok {
		return nil, false
	}
	for _, item := range arr {
		if matchesConditions(item, conditions) {
			return item, true
		}
	}
	return nil, false
}

// matchesConditions проверяет, что элемент удовлетворяет ВСЕМ условиям.
// key условия — либо имя поля (элемент это map[string]any), либо числовой
// индекс (элемент это []any, позиционный кортеж вида ["USD","9.2","9.3"]).
func matchesConditions(item any, conditions [][2]string) bool {
	for _, c := range conditions {
		key, want := c[0], c[1]
		var (
			got    any
			exists bool
		)
		if idx, err := strconv.Atoi(key); err == nil {
			if arr, ok := item.([]any); ok && idx >= 0 && idx < len(arr) {
				got, exists = arr[idx], true
			}
		} else if m, ok := item.(map[string]any); ok {
			got, exists = m[key]
		}
		if !exists || fmt.Sprint(got) != want {
			return false
		}
	}
	return true
}
