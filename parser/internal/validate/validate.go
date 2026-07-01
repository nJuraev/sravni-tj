// Package validate — семантическая пост-валидация распарсенных продуктов.
//
// Constrained decoding гарантирует ТИПЫ и СТРУКТУРУ, но не корректность
// значений. Этот пакет — последний барьер перед записью в БД
// (specs/parser.md §5, ai-output-schema.md §4).
//
// Политика частичной отбраковки (§5.4): невалидный продукт отбраковывается,
// но не валит всю задачу. Валидные продукты той же задачи записываются.
package validate

import (
	"fmt"
	"math"
	"strings"

	"sravni/parser/internal/model"
)

// epsilon — допуск для сравнения float-агрегатов ставок (защита от шума
// представления чисел). Финансовая запись в БД использует NUMERIC, здесь
// сравнение только для контроля согласованности агрегатов с сеткой.
const epsilon = 1e-6

// Result — итог валидации одного продукта.
type Result struct {
	// Product — продукт с (возможно) пересчитанными агрегатами rate_min/rate_max
	// и обнулёнными несовместимыми с категорией фичами. Валиден к записи.
	Product model.ParsedProduct
	// Warnings — несмертельные замечания (например, рассогласование агрегатов,
	// которое было исправлено). Логируются, не отбраковывают продукт.
	Warnings []string
}

// Error — отбраковка продукта по нарушению инварианта.
type Error struct {
	Reason string
}

func (e *Error) Error() string { return e.Reason }

// ValidateProduct проверяет один продукт против инвариантов §5 спецификации.
//
// taskCategory — категория задачи (bank_source_urls.category); продукт обязан
// ей соответствовать (§5.3). Возвращает *Error при отбраковке, либо Result с
// нормализованным продуктом и warnings.
func ValidateProduct(p model.ParsedProduct, taskCategory model.Category) (*Result, error) {
	var warnings []string

	// 5.5 + 5.3: имя, категория, валюта — базовые enum/целостность.
	if err := validateNamePresent(p); err != nil {
		return nil, err
	}
	if !p.Category.Valid() {
		return nil, &Error{Reason: fmt.Sprintf("недопустимая category %q", p.Category)}
	}
	// 5.3: категория продукта обязана совпадать с категорией задачи.
	if p.Category != taskCategory {
		return nil, &Error{Reason: fmt.Sprintf(
			"category продукта %q не совпадает с category задачи %q", p.Category, taskCategory)}
	}
	if !p.Currency.Valid() {
		return nil, &Error{Reason: fmt.Sprintf("недопустимая currency %q", p.Currency)}
	}

	// amount_min: значения <= 0 трактуем как «не указано» (nil). Free-модели
	// часто возвращают 0 вместо null; nullable amount_min это поглощает.
	if p.AmountMin != nil && *p.AmountMin <= 0 {
		p.AmountMin = nil
	}
	for i := range p.RateTiers {
		if p.RateTiers[i].AmountMin != nil && *p.RateTiers[i].AmountMin <= 0 {
			p.RateTiers[i].AmountMin = nil
		}
		if p.RateTiers[i].AmountMax != nil && *p.RateTiers[i].AmountMax <= 0 {
			p.RateTiers[i].AmountMax = nil
		}
	}

	// 5.2: суммы и сроки продукта.
	if err := validateAmountTerm(p); err != nil {
		return nil, err
	}

	// 5.1 + 5.2: каждый тир сетки.
	for i, t := range p.RateTiers {
		if err := validateTier(t, i); err != nil {
			return nil, err
		}
	}

	// 5.1: согласованность агрегатов rate_min/rate_max с сеткой.
	// Источник истины — rate_tiers; при расхождении агрегаты пересчитываются.
	normalized, w := reconcileAggregates(p)
	warnings = append(warnings, w...)
	p = normalized

	// 5.1: финальная проверка агрегатных ставок (после пересчёта).
	if err := validateRateRange(p.RateMin, p.RateMax, "rate_min/rate_max"); err != nil {
		return nil, err
	}

	// 4.7 схемы: категорийная согласованность фич (обнуление несовместимых).
	p.Features = normalizeFeatures(p.Features, p.Category)

	// Подкатегория: неизвестное/несоответствующее категории → "other"
	// (для installment не используется → nil).
	p.Subcategory = normalizeSubcategory(p.Subcategory, p.Category)

	return &Result{Product: p, Warnings: warnings}, nil
}

// validateNamePresent: хотя бы одно из name_ru/name_tg непустое после trim (§5.5).
func validateNamePresent(p model.ParsedProduct) error {
	if nonEmpty(p.NameRU) || nonEmpty(p.NameTG) {
		return nil
	}
	return &Error{Reason: "ни name_ru, ни name_tg не заданы (пустые имена)"}
}

// validateRateRange: 0 < rate <= 100 для обеих границ и min <= max (§5.1).
// Граница 0 недопустима (ставка 0% — вероятная галлюцинация), 100 допустима.
func validateRateRange(min, max float64, field string) error {
	if !(min > 0 && min <= 100) {
		return &Error{Reason: fmt.Sprintf("%s: rate_min=%g вне диапазона (0;100]", field, min)}
	}
	if !(max > 0 && max <= 100) {
		return &Error{Reason: fmt.Sprintf("%s: rate_max=%g вне диапазона (0;100]", field, max)}
	}
	if min > max+epsilon {
		return &Error{Reason: fmt.Sprintf("%s: rate_min=%g > rate_max=%g", field, min, max)}
	}
	return nil
}

// validateAmountTerm: суммы > 0 и сроки >= 1 для продукта (§5.2).
func validateAmountTerm(p model.ParsedProduct) error {
	if p.AmountMin != nil && !(*p.AmountMin > 0) {
		return &Error{Reason: fmt.Sprintf("amount_min=%g должна быть > 0", *p.AmountMin)}
	}
	if p.AmountMax != nil && p.AmountMin != nil && *p.AmountMax < *p.AmountMin {
		return &Error{Reason: fmt.Sprintf("amount_max=%g < amount_min=%g", *p.AmountMax, *p.AmountMin)}
	}
	if p.TermMin != nil && *p.TermMin < 1 {
		return &Error{Reason: fmt.Sprintf("term_min=%d должен быть >= 1", *p.TermMin)}
	}
	if p.TermMax != nil {
		if *p.TermMax < 1 {
			return &Error{Reason: fmt.Sprintf("term_max=%d должен быть >= 1", *p.TermMax)}
		}
		if p.TermMin != nil && *p.TermMax < *p.TermMin {
			return &Error{Reason: fmt.Sprintf("term_max=%d < term_min=%d", *p.TermMax, *p.TermMin)}
		}
	}
	return nil
}

// validateTier: ставка/суммы/сроки одного тира (§5.1, §5.2).
func validateTier(t model.RateTier, idx int) error {
	if !(t.Rate > 0 && t.Rate <= 100) {
		return &Error{Reason: fmt.Sprintf("rate_tiers[%d]: rate=%g вне диапазона (0;100]", idx, t.Rate)}
	}
	if t.AmountMin != nil && !(*t.AmountMin > 0) {
		return &Error{Reason: fmt.Sprintf("rate_tiers[%d]: amount_min=%g должна быть > 0", idx, *t.AmountMin)}
	}
	if t.AmountMin != nil && t.AmountMax != nil && *t.AmountMax < *t.AmountMin {
		return &Error{Reason: fmt.Sprintf("rate_tiers[%d]: amount_max < amount_min", idx)}
	}
	if t.AmountMax != nil && !(*t.AmountMax > 0) {
		return &Error{Reason: fmt.Sprintf("rate_tiers[%d]: amount_max=%g должна быть > 0", idx, *t.AmountMax)}
	}
	if t.TermMin != nil && *t.TermMin < 1 {
		return &Error{Reason: fmt.Sprintf("rate_tiers[%d]: term_min=%d должен быть >= 1", idx, *t.TermMin)}
	}
	if t.TermMax != nil {
		if *t.TermMax < 1 {
			return &Error{Reason: fmt.Sprintf("rate_tiers[%d]: term_max=%d должен быть >= 1", idx, *t.TermMax)}
		}
		if t.TermMin != nil && *t.TermMax < *t.TermMin {
			return &Error{Reason: fmt.Sprintf("rate_tiers[%d]: term_max < term_min", idx)}
		}
	}
	return nil
}

// reconcileAggregates: при непустой сетке rate_min/rate_max берутся из
// min/max(rate_tiers.rate) — сетка является источником истины (§5.1, §4.6 схемы).
// Расхождение с присланными агрегатами добавляется в warnings.
func reconcileAggregates(p model.ParsedProduct) (model.ParsedProduct, []string) {
	if len(p.RateTiers) == 0 {
		return p, nil
	}
	min := p.RateTiers[0].Rate
	max := p.RateTiers[0].Rate
	for _, t := range p.RateTiers[1:] {
		if t.Rate < min {
			min = t.Rate
		}
		if t.Rate > max {
			max = t.Rate
		}
	}

	var warnings []string
	if math.Abs(p.RateMin-min) > epsilon {
		warnings = append(warnings, fmt.Sprintf(
			"rate_min пересчитан из сетки: было %g, стало %g", p.RateMin, min))
	}
	if math.Abs(p.RateMax-max) > epsilon {
		warnings = append(warnings, fmt.Sprintf(
			"rate_max пересчитан из сетки: было %g, стало %g", p.RateMax, max))
	}
	p.RateMin = min
	p.RateMax = max
	return p, warnings
}

// normalizeFeatures обнуляет (в nil) фичи, несовместимые с категорией (§4.7 схемы):
// no_guarantor — только credit; capitalization/early_withdrawal — только deposit.
func normalizeFeatures(f model.Features, cat model.Category) model.Features {
	switch cat {
	case model.CategoryCredit:
		f.Capitalization = nil
		f.EarlyWithdrawal = nil
	case model.CategoryDeposit:
		f.NoGuarantor = nil
	}
	return f
}

// nonEmpty: указатель на строку не nil и непуст после trim.
func nonEmpty(s *string) bool {
	return s != nil && strings.TrimSpace(*s) != ""
}

// Допустимые подкатегории по основной категории (см. ai-output-schema §3).
var creditSubcategories = map[string]bool{
	"consumer": true, "mortgage": true, "auto": true, "business": true,
	"agro": true, "education": true, "refinance": true, "pawn": true,
}
var depositSubcategories = map[string]bool{
	"term": true, "savings": true, "demand": true, "kids": true,
}

// normalizeSubcategory приводит подкатегорию к допустимому множеству:
// для credit/deposit неизвестное/несоответствующее значение → "other";
// для installment (и прочих) подкатегория не используется → nil.
func normalizeSubcategory(s *string, cat model.Category) *string {
	other := "other"
	switch cat {
	case model.CategoryCredit:
		if s != nil {
			v := strings.ToLower(strings.TrimSpace(*s))
			if creditSubcategories[v] {
				return &v
			}
		}
		return &other
	case model.CategoryDeposit:
		if s != nil {
			v := strings.ToLower(strings.TrimSpace(*s))
			if depositSubcategories[v] {
				return &v
			}
		}
		return &other
	default:
		return nil
	}
}
