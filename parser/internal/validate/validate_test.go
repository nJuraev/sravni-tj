package validate

import (
	"testing"

	"sravni/parser/internal/model"
)

// helpers для указателей.
func sp(s string) *string  { return &s }
func fp(f float64) *float64 { return &f }
func ip(i int) *int        { return &i }
func bp(b bool) *bool      { return &b }

// validBase возвращает заведомо валидный депозит для модификации в тестах.
func validBase() model.ParsedProduct {
	return model.ParsedProduct{
		Category:  model.CategoryDeposit,
		Currency:  model.CurrencyTJS,
		NameRU:    sp("Вклад Стандарт"),
		RateMin:   10,
		RateMax:   12,
		AmountMin: fp(1000),
		TermMin:   ip(3),
		TermMax:   ip(12),
		RateTiers: []model.RateTier{
			{TermMin: ip(3), TermMax: ip(6), Rate: 10},
			{TermMin: ip(6), TermMax: ip(12), Rate: 12},
		},
	}
}

func TestValidateProduct_OK(t *testing.T) {
	res, err := ValidateProduct(validBase(), model.CategoryDeposit)
	if err != nil {
		t.Fatalf("ожидался валидный продукт, получена ошибка: %v", err)
	}
	if res.Product.RateMin != 10 || res.Product.RateMax != 12 {
		t.Fatalf("агрегаты не должны меняться: got min=%g max=%g", res.Product.RateMin, res.Product.RateMax)
	}
}

func TestValidateProduct_RejectsZeroRate(t *testing.T) {
	p := validBase()
	p.RateMin = 0
	p.RateTiers = []model.RateTier{{Rate: 0}} // 0% — галлюцинация
	if _, err := ValidateProduct(p, model.CategoryDeposit); err == nil {
		t.Fatal("ставка 0% должна отбраковываться")
	}
}

func TestValidateProduct_RejectsRateAbove100(t *testing.T) {
	p := validBase()
	p.RateTiers = []model.RateTier{{TermMin: ip(3), TermMax: ip(12), Rate: 150}}
	p.RateMax = 150
	if _, err := ValidateProduct(p, model.CategoryDeposit); err == nil {
		t.Fatal("ставка > 100% должна отбраковываться")
	}
}

func TestValidateProduct_AllowsRate100(t *testing.T) {
	p := validBase()
	p.RateTiers = []model.RateTier{{TermMin: ip(3), TermMax: ip(12), Rate: 100}}
	p.RateMin, p.RateMax = 100, 100
	if _, err := ValidateProduct(p, model.CategoryDeposit); err != nil {
		t.Fatalf("ставка ровно 100%% допустима, получена ошибка: %v", err)
	}
}

func TestValidateProduct_CoercesNonPositiveAmountToNull(t *testing.T) {
	// amount_min теперь nullable: 0/отрицательное трактуется как «не указано» (nil),
	// продукт НЕ отбраковывается (частый случай для кредитов без мин. суммы).
	p := validBase()
	p.AmountMin = fp(0)
	res, err := ValidateProduct(p, model.CategoryDeposit)
	if err != nil {
		t.Fatalf("amount_min=0 должна коэрситься в null, а не отбраковываться: %v", err)
	}
	if res.Product.AmountMin != nil {
		t.Fatal("amount_min=0 должна стать nil (не указана)")
	}
}

func TestValidateProduct_RejectsTermBelowOne(t *testing.T) {
	p := validBase()
	p.TermMin = ip(0)
	if _, err := ValidateProduct(p, model.CategoryDeposit); err == nil {
		t.Fatal("term_min < 1 должен отбраковываться")
	}
}

func TestValidateProduct_RejectsCategoryMismatch(t *testing.T) {
	p := validBase() // deposit
	if _, err := ValidateProduct(p, model.CategoryCredit); err == nil {
		t.Fatal("несовпадение категории продукта и задачи должно отбраковываться")
	}
}

func TestValidateProduct_RejectsBadCurrency(t *testing.T) {
	p := validBase()
	p.Currency = "RUB"
	if _, err := ValidateProduct(p, model.CategoryDeposit); err == nil {
		t.Fatal("валюта вне {TJS,USD,EUR} должна отбраковываться")
	}
}

func TestValidateProduct_RejectsEmptyName(t *testing.T) {
	p := validBase()
	p.NameRU = sp("   ") // только пробелы
	p.NameTG = nil
	if _, err := ValidateProduct(p, model.CategoryDeposit); err == nil {
		t.Fatal("пустые имена должны отбраковываться")
	}
}

func TestValidateProduct_NameTGOnlyIsValid(t *testing.T) {
	p := validBase()
	p.NameRU = nil
	p.NameTG = sp("Пасандози Стандартӣ")
	if _, err := ValidateProduct(p, model.CategoryDeposit); err != nil {
		t.Fatalf("name_tg без name_ru допустим, ошибка: %v", err)
	}
}

func TestValidateProduct_ReconcilesAggregatesFromTiers(t *testing.T) {
	p := validBase()
	// Присланы неверные агрегаты — должны пересчитаться из сетки (5,15).
	p.RateMin, p.RateMax = 99, 99
	p.RateTiers = []model.RateTier{
		{TermMin: ip(3), TermMax: ip(6), Rate: 5},
		{TermMin: ip(6), TermMax: ip(12), Rate: 15},
	}
	res, err := ValidateProduct(p, model.CategoryDeposit)
	if err != nil {
		t.Fatalf("неожиданная ошибка: %v", err)
	}
	if res.Product.RateMin != 5 || res.Product.RateMax != 15 {
		t.Fatalf("агрегаты должны пересчитаться из сетки: got min=%g max=%g",
			res.Product.RateMin, res.Product.RateMax)
	}
	if len(res.Warnings) == 0 {
		t.Fatal("рассогласование агрегатов должно порождать warning")
	}
}

func TestValidateProduct_NormalizesIncompatibleFeatures(t *testing.T) {
	p := validBase() // deposit
	p.Features = model.Features{
		NoGuarantor:    bp(true), // несовместимо с deposit → должно обнулиться
		Capitalization: bp(true), // совместимо
	}
	res, err := ValidateProduct(p, model.CategoryDeposit)
	if err != nil {
		t.Fatalf("неожиданная ошибка: %v", err)
	}
	if res.Product.Features.NoGuarantor != nil {
		t.Fatal("no_guarantor должно обнуляться для deposit")
	}
	if res.Product.Features.Capitalization == nil || !*res.Product.Features.Capitalization {
		t.Fatal("capitalization должно сохраняться для deposit")
	}
}

func TestValidateProduct_CreditDropsDepositFeatures(t *testing.T) {
	p := validBase()
	p.Category = model.CategoryCredit
	p.NameRU = sp("Кредит Авто")
	p.Features = model.Features{
		Capitalization:  bp(true), // несовместимо с credit
		EarlyWithdrawal: bp(true), // несовместимо с credit
		NoGuarantor:     bp(true), // совместимо
	}
	res, err := ValidateProduct(p, model.CategoryCredit)
	if err != nil {
		t.Fatalf("неожиданная ошибка: %v", err)
	}
	if res.Product.Features.Capitalization != nil || res.Product.Features.EarlyWithdrawal != nil {
		t.Fatal("депозитные фичи должны обнуляться для credit")
	}
	if res.Product.Features.NoGuarantor == nil {
		t.Fatal("no_guarantor должно сохраняться для credit")
	}
}

func TestValidateProduct_RejectsAmountMaxBelowMin(t *testing.T) {
	p := validBase()
	p.AmountMax = fp(500) // < amount_min=1000
	if _, err := ValidateProduct(p, model.CategoryDeposit); err == nil {
		t.Fatal("amount_max < amount_min должна отбраковываться")
	}
}
