# AI-output schema: контракт распарсенного продукта

**Версия:** 1.0
**Дата:** 2026-06-05
**Назначение:** строго типизированная JSON Schema, которую AI-модель (Gemini / Qwen в режиме structured output) обязана возвращать для **одного** банковского продукта. Это контракт между парсером (Go) и таблицами `products` / `product_rates` в PostgreSQL. Цель схемы — исключить «финансовые галлюцинации»: запрет лишних полей, фиксированные enum, числовые диапазоны.

> Связанные документы: [PRD.md](../../PRD.md) (раздел «Парсер»), [ТЗ.md](../../ТЗ.md) (раздел 3), [schema БД](../db/schema.md).

---

## 1. Принципы

1. **Один вызов AI = один продукт.** Со страницы банка обычно извлекается несколько продуктов. Парсер либо делает отдельный вызов на продукт, либо использует обёртку `{"products": [ <Product>, ... ]}` (см. §5). Базовый контракт ниже описывает **один** объект `Product`.
2. **Constrained decoding + post-валидация.** Схема включается как `responseSchema` (Gemini) / `input_schema` единственного форсированного tool (Anthropic-совместимые) / `json_schema` со `strict: true` (OpenAI-совместимые). После декодирования парсер **повторно** валидирует значения на семантику (диапазоны, согласованность min ≤ max) перед записью в БД — constrained decoding гарантирует типы и структуру, но не корректность значений.
3. **`additionalProperties: false` на каждом объекте.** Модель не может «дофантазировать» поля. Обязательно для strict-режима OpenAI и снижает галлюцинации у остальных.
4. **Enum вместо свободных строк** для категориальных полей (`category`, `currency`, признаки фич).
5. **Числовые диапазоны** (`minimum` / `maximum`, `exclusiveMinimum`) прямо в схеме: ставка 0–100, суммы и сроки > 0.
6. **Мультиязычность парами** `*_ru` / `*_tg`. Если на странице только один язык — заполняется он, второй = `null` (бэкенд/редактор дозаполнит). Названия (`name_ru`) обязательны хотя бы на одном языке — это обеспечивается post-валидацией, не схемой.
7. **Деньги — `number` (десятичные), не float-магия.** Суммы и ставки приходят как JSON number; в Go парсер кладёт их в `decimal`/`string` перед записью в `NUMERIC`-колонки, не используя `float64` для финансовых сравнений.
8. **Нет вычисляемых полей.** Модель не считает аннуитет/доход — это делает фронт. Схема описывает только извлечённые факты.

---

## 2. Единицы измерения и соглашения

| Поле | Единица | Комментарий |
|---|---|---|
| `rate` / `rate_min` / `rate_max` | проценты годовых, %, число | `12.5` означает 12.5% годовых. Диапазон 0–100. |
| `amount_min` / `amount_max` | единицы валюты `currency` | Минор-единицы НЕ используем; целые/дробные суммы в номинале (например `5000` сомони). |
| `term_min` / `term_max` | **месяцы** | Срок всегда нормализуется в месяцы. Год = 12. «До востребования» → `term_min = null`. |
| `currency` | enum | `TJS` \| `USD` \| `EUR`. |
| `category` | enum | `credit` \| `deposit` \| `installment` (рассрочка/исламское финансирование). |

**Почему срок в месяцах:** банки публикуют сроки и в днях, и в месяцах, и в годах. Единая единица (месяцы) на уровне контракта избавляет БД и фронт от конвертаций и делает фильтр «срок от/до» однозначным. Нормализацию дней→месяцы при необходимости делает парсер до отдачи в БД; AI просит вернуть месяцы.

---

## 3. JSON Schema (контракт одного продукта)

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://sravni.tj/schemas/parsed-product.v1.json",
  "title": "ParsedProduct",
  "type": "object",
  "additionalProperties": false,
  "required": [
    "category",
    "currency",
    "name_ru",
    "name_tg",
    "rate_min",
    "rate_max",
    "amount_min",
    "amount_max",
    "term_min",
    "term_max",
    "features",
    "rate_tiers"
  ],
  "properties": {
    "category": {
      "type": "string",
      "enum": ["credit", "deposit", "installment"],
      "description": "Тип продукта. credit — кредит/заём, deposit — вклад/депозит, installment — рассрочка/исламское финансирование (без процентной ставки, через наценку)."
    },
    "currency": {
      "type": "string",
      "enum": ["TJS", "USD", "EUR"],
      "description": "Основная валюта продукта. Если продукт предлагается в нескольких валютах с разными ставками — верни ОТДЕЛЬНЫЙ объект продукта на каждую валюту."
    },
    "name_ru": {
      "type": ["string", "null"],
      "minLength": 1,
      "maxLength": 255,
      "description": "Название продукта на русском (например «Кредит на образование»). null, если на странице нет русского названия."
    },
    "name_tg": {
      "type": ["string", "null"],
      "minLength": 1,
      "maxLength": 255,
      "description": "Название продукта на таджикском. null, если на странице нет таджикского названия."
    },
    "description_ru": {
      "type": ["string", "null"],
      "maxLength": 4000,
      "description": "Краткое описание/условия на русском. null, если отсутствует."
    },
    "description_tg": {
      "type": ["string", "null"],
      "maxLength": 4000,
      "description": "Краткое описание/условия на таджикском. null, если отсутствует."
    },
    "rate_min": {
      "type": "number",
      "minimum": 0,
      "maximum": 100,
      "description": "Минимальная годовая ставка по продукту, % (например 4.5). Если ставка единая — rate_min == rate_max."
    },
    "rate_max": {
      "type": "number",
      "minimum": 0,
      "maximum": 100,
      "description": "Максимальная годовая ставка по продукту, %. Должна быть >= rate_min."
    },
    "amount_min": {
      "type": ["number", "null"],
      "exclusiveMinimum": 0,
      "description": "Минимальная сумма продукта в валюте currency. null, если на странице мин. сумма не указана (НЕ 0)."
    },
    "amount_max": {
      "type": ["number", "null"],
      "exclusiveMinimum": 0,
      "description": "Максимальная сумма в валюте currency. null, если верхняя граница не указана (без лимита). Если задана — должна быть >= amount_min."
    },
    "term_min": {
      "type": ["integer", "null"],
      "exclusiveMinimum": 0,
      "maximum": 600,
      "description": "Минимальный срок в МЕСЯЦАХ (год = 12). null для вкладов «до востребования». Строго > 0, если задан."
    },
    "term_max": {
      "type": ["integer", "null"],
      "exclusiveMinimum": 0,
      "maximum": 600,
      "description": "Максимальный срок в МЕСЯЦАХ. null, если верхняя граница не указана. Если задан — должен быть >= term_min."
    },
    "features": {
      "type": "object",
      "additionalProperties": false,
      "description": "Булевы признаки продукта. Если про признак на странице ничего не сказано — верни null (неизвестно), НЕ false.",
      "properties": {
        "online_application": {
          "type": ["boolean", "null"],
          "description": "Возможно онлайн-оформление без визита в отделение."
        },
        "no_guarantor": {
          "type": ["boolean", "null"],
          "description": "Кредит без поручителя (только для category=credit)."
        },
        "capitalization": {
          "type": ["boolean", "null"],
          "description": "Капитализация процентов (только для category=deposit)."
        },
        "replenishable": {
          "type": ["boolean", "null"],
          "description": "Возможность пополнения вклада / досрочного частичного погашения кредита."
        },
        "early_withdrawal": {
          "type": ["boolean", "null"],
          "description": "Досрочное снятие вклада без потери процентов (только для category=deposit)."
        }
      },
      "required": [
        "online_application",
        "no_guarantor",
        "capitalization",
        "replenishable",
        "early_withdrawal"
      ]
    },
    "rate_tiers": {
      "type": "array",
      "minItems": 0,
      "maxItems": 50,
      "description": "Тарифная сетка: массив диапазонов, где ставка зависит от срока И/ИЛИ суммы. Если ставка единая для всего продукта — верни ОДИН элемент, повторяющий общие границы. Не выдумывай диапазоны, которых нет на странице.",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "required": ["term_min", "term_max", "amount_min", "amount_max", "rate"],
        "properties": {
          "term_min": {
            "type": ["integer", "null"],
            "exclusiveMinimum": 0,
            "maximum": 600,
            "description": "Нижняя граница срока этого тарифа, месяцы. null, если тариф не зависит от срока."
          },
          "term_max": {
            "type": ["integer", "null"],
            "exclusiveMinimum": 0,
            "maximum": 600,
            "description": "Верхняя граница срока этого тарифа, месяцы (включительно). null, если без верхней границы."
          },
          "amount_min": {
            "type": ["number", "null"],
            "exclusiveMinimum": 0,
            "description": "Нижняя граница суммы этого тарифа в валюте currency. null, если тариф не зависит от суммы."
          },
          "amount_max": {
            "type": ["number", "null"],
            "exclusiveMinimum": 0,
            "description": "Верхняя граница суммы этого тарифа. null, если без верхней границы."
          },
          "rate": {
            "type": "number",
            "minimum": 0,
            "maximum": 100,
            "description": "Годовая ставка для этой комбинации срок×сумма, %."
          }
        }
      }
    },
    "source_note": {
      "type": ["string", "null"],
      "maxLength": 500,
      "description": "Необязательная заметка о неоднозначности извлечения (например «ставка указана диапазоном без привязки к сумме»). Для отладки, не для витрины."
    }
  }
}
```

---

## 4. Семантическая post-валидация (в коде парсера, после декодирования)

Constrained decoding НЕ проверяет эти инварианты — парсер обязан проверить их сам и при нарушении уйти в retry (до 3 попыток) или пометить запуск ошибкой:

1. `rate_min <= rate_max`.
2. `amount_max IS NULL OR amount_max >= amount_min`.
3. `term_max IS NULL OR term_min IS NULL OR term_max >= term_min`.
4. Хотя бы одно из `name_ru` / `name_tg` не `null` и непустое.
5. Для каждого элемента `rate_tiers`: `tier.rate` в [0,100]; `term_max >= term_min` и `amount_max >= amount_min` (с учётом null).
6. Согласованность агрегатов: `rate_min == MIN(rate_tiers.rate)` и `rate_max == MAX(rate_tiers.rate)` при непустом `rate_tiers`. Если расходится — парсер пересчитывает агрегаты из `rate_tiers` (источник истины — сетка) и логирует расхождение.
7. Категорийная согласованность фич: `no_guarantor` осмыслено только для `credit`; `capitalization` / `early_withdrawal` — только для `deposit`. Невалидные комбинации обнуляются в `null`.

**Источник истины по ставкам — `rate_tiers`.** Поля `rate_min` / `rate_max` продукта вычисляются из сетки (для денормализованного быстрого фильтра в БД — см. [schema.md](../db/schema.md), раздел «Решение по тарифной сетке»).

---

## 5. Обёртка для нескольких продуктов со страницы (опционально)

Когда одна страница содержит несколько продуктов, парсер использует обёртку. Внутренний объект — тот же `ParsedProduct`:

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": ["products"],
  "properties": {
    "products": {
      "type": "array",
      "minItems": 0,
      "maxItems": 30,
      "items": { "$ref": "https://sravni.tj/schemas/parsed-product.v1.json" }
    }
  }
}
```

> Глубина вложенности ограничена (продукт → rate_tiers → поля), чтобы не упереться в `max_tokens` и не раздувать латентность декодирования. `maxItems` на массивах — защита от «runaway»-генерации.

---

## 6. Соответствие полей схемы и таблиц БД

| Поле AI-схемы | Таблица.колонка | Примечание |
|---|---|---|
| `category` | `products.category` | enum |
| `currency` | `products.currency` | enum |
| `name_ru` / `name_tg` | `products.name_ru` / `name_tg` | |
| `description_ru` / `description_tg` | `products.description_ru` / `description_tg` | |
| `rate_min` / `rate_max` | `products.rate_min` / `rate_max` | денормализовано из `rate_tiers` |
| `amount_min` / `amount_max` | `products.amount_min` / `amount_max` | |
| `term_min` / `term_max` | `products.term_min` / `term_max` | месяцы |
| `features` | `products.features` (jsonb) | |
| `rate_tiers[]` | `product_rates` (строки) | по строке на элемент массива |
| `source_note` | `parser_runs` (через лог) | на витрину не идёт |

Поля, которые AI **не** заполняет (проставляет парсер/БД): `id`, `bank_id`, `source_url_id`, `status` (по умолчанию `draft` или `active` по политике), `parsed_at`, `created_at`, `updated_at`.
