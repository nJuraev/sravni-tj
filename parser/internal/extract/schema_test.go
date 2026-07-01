package extract

import (
	"testing"

	"sravni/parser/internal/model"
)

// validJSON — корректный ответ AI по контракту схемы (один депозит с сеткой).
const validJSON = `{
  "products": [
    {
      "category": "deposit",
      "currency": "TJS",
      "name_ru": "Вклад Стандарт",
      "name_tg": null,
      "description_ru": null,
      "description_tg": null,
      "rate_min": 10,
      "rate_max": 12,
      "amount_min": 1000,
      "amount_max": null,
      "term_min": 3,
      "term_max": 12,
      "features": {
        "online_application": true,
        "no_guarantor": null,
        "capitalization": true,
        "replenishable": false,
        "early_withdrawal": null
      },
      "rate_tiers": [
        {"term_min": 3, "term_max": 6, "amount_min": null, "amount_max": null, "rate": 10},
        {"term_min": 6, "term_max": 12, "amount_min": null, "amount_max": null, "rate": 12}
      ],
      "source_note": null
    }
  ]
}`

func TestDecodeExtraction_Valid(t *testing.T) {
	res, err := decodeExtraction(validJSON)
	if err != nil {
		t.Fatalf("корректный JSON должен парситься, ошибка: %v", err)
	}
	if len(res.Products) != 1 {
		t.Fatalf("ожидался 1 продукт, получено %d", len(res.Products))
	}
	p := res.Products[0]
	if p.Category != model.CategoryDeposit || p.Currency != model.CurrencyTJS {
		t.Fatalf("неверные enum: category=%q currency=%q", p.Category, p.Currency)
	}
	if p.NameRU == nil || *p.NameRU != "Вклад Стандарт" {
		t.Fatal("name_ru разобрано неверно")
	}
	if p.NameTG != nil {
		t.Fatal("name_tg должно быть nil (null в JSON)")
	}
	if p.AmountMax != nil {
		t.Fatal("amount_max должно быть nil (null)")
	}
	if len(p.RateTiers) != 2 || p.RateTiers[1].Rate != 12 {
		t.Fatal("rate_tiers разобраны неверно")
	}
	if p.Features.OnlineApplication == nil || !*p.Features.OnlineApplication {
		t.Fatal("features.online_application должно быть true")
	}
	if p.Features.NoGuarantor != nil {
		t.Fatal("features.no_guarantor должно быть nil (null)")
	}
}

func TestDecodeExtraction_CodeFenceStripped(t *testing.T) {
	wrapped := "```json\n" + validJSON + "\n```"
	res, err := decodeExtraction(wrapped)
	if err != nil {
		t.Fatalf("JSON в ```json-блоке должен разбираться, ошибка: %v", err)
	}
	if len(res.Products) != 1 {
		t.Fatalf("ожидался 1 продукт, получено %d", len(res.Products))
	}
}

func TestDecodeExtraction_EmptyProducts(t *testing.T) {
	res, err := decodeExtraction(`{"products": []}`)
	if err != nil {
		t.Fatalf("пустой массив продуктов валиден, ошибка: %v", err)
	}
	if len(res.Products) != 0 {
		t.Fatal("ожидался пустой список продуктов")
	}
}

func TestDecodeExtraction_BrokenJSON(t *testing.T) {
	cases := map[string]string{
		"оборванный JSON":       `{"products": [ {"category": "deposit" `,
		"не объект":             `[1,2,3]`,
		"мусор":                 `это не json вовсе`,
		"лишнее поле (анти-галлюцинация)": `{"products": [], "extra_field": 1}`,
	}
	for name, raw := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := decodeExtraction(raw); err == nil {
				t.Fatalf("битый ввод %q должен давать ошибку", name)
			}
		})
	}
}

func TestStripCodeFence(t *testing.T) {
	cases := []struct{ in, want string }{
		{"```json\n{\"a\":1}\n```", `{"a":1}`},
		{"```\n{\"a\":1}\n```", `{"a":1}`},
		{`{"a":1}`, `{"a":1}`},
		{"  ```json\n{\"a\":1}\n```  ", `{"a":1}`},
	}
	for _, c := range cases {
		if got := stripCodeFence(c.in); got != c.want {
			t.Fatalf("stripCodeFence(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}

// TestResponseSchema_TopLevelShape — схема имеет ожидаемую форму обёртки.
func TestResponseSchema_TopLevelShape(t *testing.T) {
	s := responseSchema()
	if s["additionalProperties"] != false {
		t.Fatal("корневой объект должен иметь additionalProperties:false")
	}
	props, ok := s["properties"].(map[string]any)
	if !ok {
		t.Fatal("schema.properties отсутствует")
	}
	if _, ok := props["products"]; !ok {
		t.Fatal("schema должна содержать products")
	}
}
