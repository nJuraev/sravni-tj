// Package model содержит общие доменные типы парсера: задачи, enum-значения
// и типизированный результат AI-извлечения, точно соответствующий
// docs/parser/ai-output-schema.md.
package model

// Category — категория продукта (enum). Совпадает с CHECK в БД и AI-схемой.
type Category string

const (
	CategoryCredit      Category = "credit"
	CategoryDeposit     Category = "deposit"
	CategoryInstallment Category = "installment" // рассрочка / исламское финансирование (Alif, Tawhid)
)

// Valid сообщает, входит ли категория в допустимое множество.
func (c Category) Valid() bool {
	return c == CategoryCredit || c == CategoryDeposit || c == CategoryInstallment
}

// Currency — валюта продукта (enum).
type Currency string

const (
	CurrencyTJS Currency = "TJS"
	CurrencyUSD Currency = "USD"
	CurrencyEUR Currency = "EUR"
)

// Valid сообщает, входит ли валюта в допустимое множество.
func (c Currency) Valid() bool {
	return c == CurrencyTJS || c == CurrencyUSD || c == CurrencyEUR
}

// SourceTask — одна задача парсинга, строка bank_source_urls (is_active=true).
type SourceTask struct {
	ID       int64    // bank_source_urls.id
	BankID   int64    // bank_source_urls.bank_id
	Category Category // category задачи; продукты обязаны ей соответствовать
	URL      string   // URL страницы для скрейпинга
}

// DiscoveryInstruction — инструкция discovery-парсера (bank_parse_instructions,
// kind='product_discovery', is_active=true): с какой страницы и с какими
// подсказками искать ссылки на страницы продуктов конкретного банка/категории.
type DiscoveryInstruction struct {
	ID           int64    // bank_parse_instructions.id
	BankID       int64    // привязка к банку
	Category     Category // категория искомых продуктов
	StartURL     string   // стартовая страница обхода (главная/раздел)
	MenuSections []string // секции меню-подсказки для AI (может быть пустым)
	Notes        *string  // свободная подсказка AI или nil
}

// Features — булевы признаки продукта. nil = «неизвестно» (на странице нет
// данных о признаке). См. ai-output-schema.md §3 (features.*: boolean|null).
type Features struct {
	OnlineApplication *bool `json:"online_application"`
	NoGuarantor       *bool `json:"no_guarantor"`
	Capitalization    *bool `json:"capitalization"`
	Replenishable     *bool `json:"replenishable"`
	EarlyWithdrawal   *bool `json:"early_withdrawal"`
}

// RateTier — одна ячейка тарифной сетки (срок×сумма→ставка).
// Поля-указатели = nullable по контракту AI-схемы.
type RateTier struct {
	TermMin   *int     `json:"term_min"`   // месяцы; null = не зависит от срока
	TermMax   *int     `json:"term_max"`   // месяцы; null = без верхней границы
	AmountMin *float64 `json:"amount_min"` // null = не зависит от суммы
	AmountMax *float64 `json:"amount_max"` // null = без верхней границы
	Rate      float64  `json:"rate"`       // % годовых, [0..100]
}

// ParsedProduct — один продукт, извлечённый AI (контракт ai-output-schema.md §3).
// Указатели используются для nullable-полей; не-указатели — для required-полей,
// которые AI обязан вернуть всегда (constrained decoding это гарантирует).
type ParsedProduct struct {
	Category      Category   `json:"category"`
	Subcategory   *string    `json:"subcategory"` // подкатегория (enum, см. ai-output-schema §3); null для installment
	Currency      Currency   `json:"currency"`
	NameRU        *string    `json:"name_ru"` // обязателен хотя бы один из name_ru/name_tg
	NameTG        *string    `json:"name_tg"`
	DescriptionRU *string    `json:"description_ru"`
	DescriptionTG *string    `json:"description_tg"`
	KeyConditionsRU []string `json:"key_conditions_ru"` // буллеты условий сверх ставки/суммы/срока (0% предоплаты, комиссия по сегментам…); nil = на странице такого текста нет
	KeyConditionsTG []string `json:"key_conditions_tg"`
	DocumentsRU     []string `json:"documents_ru"` // минимальный пакет документов
	DocumentsTG     []string `json:"documents_tg"`
	RateMin       float64    `json:"rate_min"`
	RateMax       float64    `json:"rate_max"`
	AmountMin     *float64   `json:"amount_min"` // null = минимальная сумма не указана/не ограничена
	AmountMax     *float64   `json:"amount_max"` // null = без верхнего предела
	TermMin       *int       `json:"term_min"`   // null = до востребования
	TermMax       *int       `json:"term_max"`   // null = без верхней границы
	Features      Features   `json:"features"`
	RateTiers     []RateTier `json:"rate_tiers"`
	IsSpecial     bool       `json:"is_special"` // особый/аномальный продукт (рефинансирование, реструктуризация и т.п.) — по умолчанию скрыт из обычной выдачи (products.is_special)
	SourceNote    *string    `json:"source_note"` // для отладки, не на витрину
}

// RateRow — одна строка курса валюты, извлечённая AI со страницы курсов.
// buy/sell — указатели: банк может не котировать одну из сторон (null).
type RateRow struct {
	Currency string   `json:"currency"` // ISO-код (USD/EUR/RUB/…)
	Category string   `json:"category"` // cash | transfer
	Buy      *float64 `json:"buy"`      // банк покупает у клиента; null — не котируется
	Sell     *float64 `json:"sell"`     // банк продаёт клиенту; null — не котируется
}

// RatesResult — обёртка ответа AI для страницы курсов.
type RatesResult struct {
	Rates []RateRow `json:"rates"`
}

// ProductLink — ссылка на детальную страницу продукта из каталога (index-режим),
// с опциональной подсказкой раздела меню (для классификации подкатегории).
type ProductLink struct {
	URL     string  `json:"url"`
	Section *string `json:"section"` // заголовок раздела меню или null
}

// ExtractionResult — обёртка ответа AI: массив продуктов со страницы (§5 схемы).
type ExtractionResult struct {
	Products []ParsedProduct `json:"products"`
	// ProductLinks — index-режим: если страница это каталог/меню, лишь
	// ссылающийся на отдельные страницы продуктов (полных условий на ней нет),
	// модель возвращает сюда ссылки {url, section}, а Products пустой.
	ProductLinks []ProductLink `json:"product_links"`
}
