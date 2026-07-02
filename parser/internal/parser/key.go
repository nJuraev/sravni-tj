package parser

import (
	"strings"

	"sravni/parser/internal/model"
)

// externalKey формирует стабильный ключ идемпотентности продукта в рамках
// источника: normalize(name) + "|" + currency (schema.md §9, открытый вопрос §10.4).
//
// Берётся name_ru, иначе name_tg (один из них гарантированно непуст после
// валидации). Нормализация: trim + lower + схлопывание пробелов — чтобы мелкие
// различия вёрстки не «плодили» дубли между прогонами.
func externalKey(p model.ParsedProduct) string {
	name := ""
	if p.NameRU != nil && strings.TrimSpace(*p.NameRU) != "" {
		name = *p.NameRU
	} else if p.NameTG != nil {
		name = *p.NameTG
	}
	return normalizeName(name) + "|" + string(p.Currency)
}

// genericNamePrefixes — родовые слова, которые AI то включает в название, то
// опускает (зависит от того, извлечён ли продукт с каталожной страницы или с
// детальной) — из-за этого один и тот же продукт получает разные ключи и
// дублируется («Депозит «Надежный»» vs «Надежный»). Отбрасываем перед сравнением.
var genericNamePrefixes = []string{"депозит ", "вклад ", "кредит ", "заём ", "займ "}

// decorativeReplacer убирает кавычки/пунктуацию, которую AI ставит непоследовательно.
var decorativeReplacer = strings.NewReplacer(
	"«", "", "»", "", `"`, "", "'", "", "'", "", "'", "", ".", "", ",", "",
)

// normalizeName приводит имя к каноничной форме для ключа.
func normalizeName(s string) string {
	s = strings.ToLower(strings.TrimSpace(s))
	s = decorativeReplacer.Replace(s)
	s = strings.Join(strings.Fields(s), " ")
	for _, prefix := range genericNamePrefixes {
		if strings.HasPrefix(s, prefix) {
			s = strings.TrimPrefix(s, prefix)
			break
		}
	}
	return strings.TrimSpace(s)
}
