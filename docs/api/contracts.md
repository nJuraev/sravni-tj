# API Contracts — Sravni.tj

**Статус:** контракт между `backend/` (Laravel) и `frontend/` (Vue). Ревизия от 2026-06-21: каталог разнесён на типовые эндпоинты (`/products/credits|deposits|installments`), добавлен флаг `is_special` и параметр `special`. Далее — снова замороженная база, изменения только аддитивные.
**База:** все пути под префиксом `/api`. Без авторизации (MVP).
**Формат:** JSON (`Content-Type: application/json`). Кодировка UTF-8.
**Версионирование:** изменения только аддитивные (новые опциональные поля/параметры). Удаление/смена типа существующих полей — breaking change, запрещено без новой версии. (Принцип «addition over modification».)

---

## Общие соглашения

### Локализация и валюта

- Мультиязычные поля приходят парами: `*_ru` (всегда заполнено) и `*_tg` (может быть `null`). Клиент выбирает по активному locale, с fallback на `*_ru`.
- Заголовок `Accept-Language: ru | tg` влияет на язык сообщений об ошибках валидации. Дефолт — `ru`.
- `currency` продукта — одно из `TJS` | `USD` | `EUR`. Один продукт = одна валюта. Конвертация не выполняется.

### Числовые типы

- Ставки (`rate*`) — number (проценты годовых), `0 < rate <= 100`.
- Суммы (`amount*`) — number в валюте продукта; верхняя граница может быть `null` (= без предела).
- Сроки (`term*`) — integer в месяцах; верхняя граница может быть `null` (= без предела).

### Формат ошибок (единый)

**`422 Unprocessable Entity`** — невалидные данные (стандартный Laravel):

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "<field>": ["<локализованное сообщение>", "..."]
  }
}
```

**`404 Not Found`**:

```json
{ "message": "Resource not found." }
```

**`500 Internal Server Error`** — без раскрытия деталей реализации:

```json
{ "message": "Server error." }
```

Семантика статусов едина для всех эндпоинтов: `200` чтение ок, `201` создано, `404` нет/скрыт, `422` семантически невалидно, `405` метод не поддержан, `500` внутренняя ошибка. Запрещено отдавать «200 с error внутри».

---

## Схема объекта `Product`

Возвращается в `GET /api/products/{credits|deposits|installments}` (в массиве `data`) и `GET /api/products/{id}` (как `data`).

| Поле | Тип | Null? | Описание |
|---|---|---|---|
| `id` | integer | нет | идентификатор продукта |
| `category` | enum `credit`\|`deposit`\|`installment` | нет | категория |
| `subcategory` | enum (см. ниже) | да | подкатегория; `null` для `installment` или если не классифицирована |
| `is_special` | boolean | нет | «особый» (аномальный) продукт; скрыт из выдачи, пока не запрошен `?special=true` |
| `currency` | enum `TJS`\|`USD`\|`EUR` | нет | валюта продукта |
| `name_ru` | string | нет | название (рус.) |
| `name_tg` | string | да | название (тадж.); `null` если перевода нет |
| `description_ru` | string | да | описание (рус.) |
| `description_tg` | string | да | описание (тадж.) |
| `rate_min` | number | нет | минимальная годовая ставка, % (агрегат по сетке) |
| `rate_max` | number | нет | максимальная годовая ставка, % (агрегат по сетке) |
| `amount_min` | number | да | минимальная сумма; `null` = не указана |
| `amount_max` | number | да | максимальная сумма; `null` = без предела |
| `term_min` | integer | нет | минимальный срок, месяцев |
| `term_max` | integer | да | максимальный срок, месяцев; `null` = без предела |
| `rate_tiers` | array<RateTier> | нет | тарифная сетка (см. ниже); минимум 1 элемент |
| `features` | object | нет | признаки (см. ниже) |
| `bank` | object `BankRef` | нет | краткие данные банка |
| `parsed_at` | string (ISO 8601) | да | время последнего парсинга |

#### `subcategory` (enum)

Заполняется парсером (AI-классификация). Допустимые коды:

| Категория | Коды |
|---|---|
| `credit` | `consumer`, `mortgage`, `auto`, `business`, `agro`, `education`, `refinance`, `pawn` |
| `deposit` | `term`, `savings`, `demand`, `kids` |
| общий fallback | `other` |
| `installment` | — (всегда `null`) |

> Набор кодов фиксирован (CHECK-констрейнт в БД). Расширение — только аддитивно.

### `RateTier` (ячейка тарифной сетки: ставка × срок × сумма × валюта)

| Поле | Тип | Null? | Описание |
|---|---|---|---|
| `currency` | enum `TJS`\|`USD`\|`EUR` | нет | валюта тира (== `currency` продукта) |
| `amount_from` | number | нет | нижняя граница суммы |
| `amount_to` | number | да | верхняя граница суммы; `null` = без предела |
| `term_from` | integer | нет | нижняя граница срока, месяцев |
| `term_to` | integer | да | верхняя граница срока, месяцев; `null` = без предела |
| `rate` | number | нет | ставка для этой ячейки, % |

### `features` (object)

| Ключ | Тип | Описание |
|---|---|---|
| `online_application` | boolean | онлайн-оформление |
| `no_guarantor` | boolean | без поручителя |
| `capitalization` | boolean | капитализация процентов (депозит) |
| `replenishment` | boolean | возможность пополнения (депозит) |

> Отсутствующие/неизвестные признаки трактуются как `false`. Набор ключей может расширяться аддитивно.

### `BankRef` (краткие данные банка внутри продукта)

| Поле | Тип | Null? | Описание |
|---|---|---|---|
| `id` | integer | нет | id банка |
| `name_ru` | string | нет | название (рус.) |
| `name_tg` | string | да | название (тадж.) |
| `is_partner` | boolean | нет | признак партнёра (информационно; на доставку заявок не влияет) |

---

## GET /api/products/{credits|deposits|installments}

Каталог разнесён по типам продукта — категория задаётся ЭНДПОИНТОМ, не query-параметром:

| Эндпоинт | Категория | Дефолтная сортировка |
|---|---|---|
| `GET /api/products/credits` | `credit` | `rate_min` возр. (выгодное = меньший %) |
| `GET /api/products/deposits` | `deposit` | `-rate_max` убыв. (выгодное = больший %) |
| `GET /api/products/installments` | `installment` | `term_min` возр. (ставки нет) |

Все три принимают одинаковый набор фильтров/пагинации (ниже).
**Возвращаются только продукты с `status=active` И банком `status=active`.**
**«Особые» (`is_special=true`) аномальные продукты по умолчанию СКРЫТЫ** —
показываются только при `?special=true`.

### Query-параметры

| Параметр | Тип | Обяз. | Допустимые значения / формат | Описание |
|---|---|---|---|---|
| `special` | boolean | нет | `true`/`false`; дефолт `false` | подмешать «особые» (`is_special`) продукты к обычным |
| `subcategory[]` | array<string> | нет | коды подкатегорий (см. схему `Product`) | продукт любой из перечисленных подкатегорий |
| `bank_id[]` | array<integer> | нет | id существующих банков | продукт любого из перечисленных банков |
| `currency` | string | нет | `TJS` \| `USD` \| `EUR` | фильтр по валюте |
| `amount_min` | number | нет | `> 0` | нижняя граница искомой суммы |
| `amount_max` | number | нет | `>= amount_min` | верхняя граница искомой суммы |
| `term_min` | integer | нет | `>= 1` | нижняя граница срока (мес.) |
| `term_max` | integer | нет | `>= term_min` | верхняя граница срока (мес.) |
| `rate_min` | number | нет | `0 < x <= 100` | нижняя граница ставки, % |
| `rate_max` | number | нет | `rate_min <= x <= 100` | верхняя граница ставки, % |
| `features[]` | array<string> | нет | `online_application`,`no_guarantor`,`capitalization`,`replenishment` | продукт должен иметь ВСЕ перечисленные признаки = true |
| `sort` | string | нет | `rate_min`,`rate_max`,`amount_min`,`term_min`,`created_at`; префикс `-` = убывание | сортировка; дефолт зависит от эндпоинта (см. таблицу выше) |
| `page` | integer | нет | `>= 1`; дефолт `1` | номер страницы |
| `per_page` | integer | нет | `1..100`; дефолт `20` | размер страницы |

#### Семантика фильтров (фиксировано — см. `backend.md` §5.3–5.4)

- **Сумма / срок:** фильтр по ПЕРЕСЕЧЕНИЮ. Продукт проходит, если запрошенный диапазон пересекается с диапазоном продукта; `null` верхней границы продукта = `+∞`.
- **Ставка (базовый режим):** продукт проходит, если `[product.rate_min, product.rate_max]` пересекается с `[rate_min, rate_max]` (фильтр по агрегатам).
- **Ставка (точный режим по сетке):** если переданы ОДНОВРЕМЕННО `currency` + (`amount_min` и/или `amount_max`) + (`term_min` и/или `term_max`) + (`rate_min` и/или `rate_max`), backend дополнительно требует существования тира `rate_tiers`, у которого: `currency` совпадает, запрошенная сумма ∈ `[amount_from, amount_to]`, запрошенный срок ∈ `[term_from, term_to]`, и `rate` ∈ `[rate_min, rate_max]`. Так фронт фильтрует «по конкретной ячейке тарифной сетки».
- Невалидное значение enum (`currency`/`subcategory`/`sort`) или нарушение `min<=max` → `422`.
- В ответе ВСЕГДА присутствуют и агрегаты (`rate_min`/`rate_max`), и полная `rate_tiers` — клиент сам подсвечивает релевантную ячейку.

### Пример запроса

```
GET /api/products/deposits?currency=TJS&amount_min=10000&term_min=12&term_max=24&rate_min=10&sort=-rate_max&page=1&per_page=20
Accept-Language: ru
```

### Пример ответа `200`

```json
{
  "data": [
    {
      "id": 101,
      "category": "deposit",
      "subcategory": "savings",
      "is_special": false,
      "currency": "TJS",
      "name_ru": "Вклад «Накопительный»",
      "name_tg": "Амонати «Андӯхтӣ»",
      "description_ru": "Срочный вклад с капитализацией.",
      "description_tg": null,
      "rate_min": 10.0,
      "rate_max": 16.5,
      "amount_min": 5000,
      "amount_max": null,
      "term_min": 3,
      "term_max": 36,
      "rate_tiers": [
        {
          "currency": "TJS",
          "amount_from": 5000,
          "amount_to": 50000,
          "term_from": 3,
          "term_to": 11,
          "rate": 10.0
        },
        {
          "currency": "TJS",
          "amount_from": 5000,
          "amount_to": 50000,
          "term_from": 12,
          "term_to": 36,
          "rate": 13.0
        },
        {
          "currency": "TJS",
          "amount_from": 50000.01,
          "amount_to": null,
          "term_from": 12,
          "term_to": 36,
          "rate": 16.5
        }
      ],
      "features": {
        "online_application": true,
        "no_guarantor": false,
        "capitalization": true,
        "replenishment": true
      },
      "bank": {
        "id": 7,
        "name_ru": "Банк Эсхата",
        "name_tg": "Бонки Эсхата",
        "is_partner": true
      },
      "parsed_at": "2026-06-05T03:12:00Z"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total_items": 42,
    "total_pages": 3
  }
}
```

### Пример ответа `200` (пустой результат)

```json
{
  "data": [],
  "pagination": { "page": 1, "per_page": 20, "total_items": 0, "total_pages": 0 }
}
```

### Пример ответа `422` (невалидный фильтр)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "currency": ["Значение currency должно быть одним из: TJS, USD, EUR."],
    "rate_max": ["rate_max должно быть больше или равно rate_min."]
  }
}
```

---

## GET /api/products/{id}

Карточка одного продукта. Доступен только если продукт `status=active` И его банк `status=active`; иначе `404`.

### Пример запроса

```
GET /api/products/101
Accept-Language: tg
```

### Пример ответа `200`

```json
{
  "data": {
    "id": 101,
    "category": "deposit",
    "subcategory": "savings",
    "is_special": false,
    "currency": "TJS",
    "name_ru": "Вклад «Накопительный»",
    "name_tg": "Амонати «Андӯхтӣ»",
    "description_ru": "Срочный вклад с капитализацией.",
    "description_tg": null,
    "rate_min": 10.0,
    "rate_max": 16.5,
    "amount_min": 5000,
    "amount_max": null,
    "term_min": 3,
    "term_max": 36,
    "rate_tiers": [
      { "currency": "TJS", "amount_from": 5000, "amount_to": 50000, "term_from": 3,  "term_to": 11, "rate": 10.0 },
      { "currency": "TJS", "amount_from": 5000, "amount_to": 50000, "term_from": 12, "term_to": 36, "rate": 13.0 }
    ],
    "features": {
      "online_application": true,
      "no_guarantor": false,
      "capitalization": true,
      "replenishment": true
    },
    "bank": {
      "id": 7,
      "name_ru": "Банк Эсхата",
      "name_tg": "Бонки Эсхата",
      "is_partner": true
    },
    "parsed_at": "2026-06-05T03:12:00Z"
  }
}
```

### Пример ответа `404` (нет / скрыт / банк неактивен)

```json
{ "message": "Resource not found." }
```

---

## GET /api/banks

Список **активных** банков (`status=active`).

### Query-параметры

| Параметр | Тип | Обяз. | Описание |
|---|---|---|---|
| (нет) | — | — | список не пагинируется (до 20 банков на MVP) |

### Схема объекта `Bank`

| Поле | Тип | Null? | Описание |
|---|---|---|---|
| `id` | integer | нет | id банка |
| `name_ru` | string | нет | название (рус.) |
| `name_tg` | string | да | название (тадж.) |
| `is_partner` | boolean | нет | признак партнёра (информационно) |

> Поля `email` и `status` НЕ отдаются наружу (внутренние/служебные).

### Пример ответа `200`

```json
{
  "data": [
    { "id": 7, "name_ru": "Банк Эсхата",   "name_tg": "Бонки Эсхата",   "is_partner": true  },
    { "id": 9, "name_ru": "Алиф Банк",      "name_tg": "Алиф Бонк",       "is_partner": false },
    { "id": 12, "name_ru": "Душанбе Сити",  "name_tg": null,              "is_partner": false }
  ]
}
```

---

## POST /api/leads

Приём заявки на продукт. При успехе: запись в `leads` + email на `banks.email` соответствующего банка.

### Тело запроса

| Поле | Тип | Обяз. | Правило | Описание |
|---|---|---|---|---|
| `full_name` | string | да | 2–255 символов | ФИО заявителя |
| `phone` | string | да | формат телефона (нормализуется на сервере) | телефон |
| `product_id` | integer | да | должен ссылаться на ВИДИМЫЙ продукт (active + банк active) | продукт заявки |
| `consent` | boolean | да | **должен быть `true`** | согласие на обработку перс. данных |

> `bank_id` НЕ передаётся клиентом — определяется сервером по `product_id` (целостность).
> Любое `consent != true` → `422`. Заявка без согласия не сохраняется, email не отправляется.

### Пример запроса

```
POST /api/leads
Content-Type: application/json
Accept-Language: ru

{
  "full_name": "Иван Иванов",
  "phone": "+992 90 123 45 67",
  "product_id": 101,
  "consent": true
}
```

### Пример ответа `201 Created`

```json
{
  "data": {
    "id": 555,
    "product_id": 101,
    "bank_id": 7,
    "full_name": "Иван Иванов",
    "phone": "+992901234567",
    "consent": true,
    "created_at": "2026-06-05T08:40:00Z"
  },
  "message": "Заявка принята."
}
```

> `201` означает «лид сохранён». Доставка email — best-effort (очередь + ретраи); сбой почты не меняет код ответа.

### Пример ответа `422` — нет согласия

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "consent": ["Необходимо согласие на обработку персональных данных."]
  }
}
```

### Пример ответа `422` — несколько ошибок

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "full_name": ["Поле ФИО обязательно."],
    "phone": ["Поле телефон обязательно."],
    "product_id": ["Выбранный продукт недоступен."],
    "consent": ["Необходимо согласие на обработку персональных данных."]
  }
}
```

---

## Сводная таблица кодов ответов

| Эндпоинт | Успех | Ошибки |
|---|---|---|
| `GET /api/products/credits` | `200` | `422` (невалидный фильтр/сортировка) |
| `GET /api/products/deposits` | `200` | `422` (невалидный фильтр/сортировка) |
| `GET /api/products/installments` | `200` | `422` (невалидный фильтр/сортировка) |
| `GET /api/products/{id}` | `200` | `404` (нет/скрыт/банк неактивен) |
| `GET /api/banks` | `200` | — |
| `POST /api/leads` | `201` | `422` (невалидные данные / `consent != true` / скрытый product_id), `404` (несуществующий маршрут) |

---

## Инварианты контракта (для обеих сторон)

1. Каталог отдаёт только `active`-продукты с `active`-банком; скрытое недоступно даже по id (`404`).
2. `rate_*` агрегаты согласованы с `rate_tiers`; обе формы всегда присутствуют в ответе.
3. Мультиязычные поля приходят парами; `*_tg` может быть `null` → fallback на `*_ru`.
4. Один продукт = одна валюта.
5. `consent != true` → `422`; `bank_id` лида определяется сервером; `is_partner` не влияет на доставку.
6. Формат ошибок единый (`message` + опц. `errors{}`); статусы не смешиваются.
7. Изменения контракта — только аддитивные.
