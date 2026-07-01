# Parser — парсер банковских продуктов Sravni.tj (Go)

Собирает условия по кредитам и депозитам с сайтов банков Таджикистана:
скрейп страницы → Markdown → AI structured output → валидация → запись в
PostgreSQL. Запускается **по крону** (один процесс на запуск).

Спецификация поведения: [`docs/specs/parser.md`](../docs/specs/parser.md).
Контракт AI-вывода: [`docs/parser/ai-output-schema.md`](../docs/parser/ai-output-schema.md).
Схема БД: [`docs/db/schema.md`](../docs/db/schema.md).

## Архитектура (layout)

```
parser/
├── cmd/parser/main.go        # точка входа: один прогон, затем exit
└── internal/
    ├── config/               # чтение и валидация env
    ├── model/                # доменные типы + типы AI-схемы (enum, тиры, фичи)
    ├── scrape/               # интерфейс Scraper + Firecrawl/Jina (HTML→Markdown)
    ├── extract/              # интерфейс AIExtractor + Gemini/Qwen + JSON Schema
    ├── validate/             # семантическая пост-валидация (диапазоны, инварианты)
    ├── store/                # интерфейс Store + pgx-реализация (upsert, parser_runs)
    └── parser/               # оркестратор пайплайна (retry/backoff, изоляция задач)
```

## Pipeline (одна задача = одна строка `bank_source_urls`)

```
scrape → extract → validate → split-by-currency → upsert (+ outdate) → log(parser_runs)
```

- **Идемпотентность:** upsert по `(source_url_id, external_key)`, где
  `external_key = normalize(name) + "|" + currency`.
- **1 продукт = 1 валюта:** мультивалютный продукт банка — это N записей `products`.
- **status при вставке = `draft`** (DEFAULT в БД); администраторский статус
  при обновлении не перетирается.
- **Устаревание:** при успешном непустом прогоне продукты источника, не
  встреченные в этом прогоне, переводятся в `outdated`.
- **Изоляция:** падение одной задачи не валит остальные; код выхода 0 при
  частичных провалах, ненулевой — только при фатальном сбое (нет БД/конфига).
- **Ретраи:** транзиентные ошибки (сеть, 5xx, 429, БД) — экспоненциальный
  backoff (1s→2s→4s, до 3 попыток), уважается `Retry-After`. Ошибки валидации
  не ретраятся.

## Переменные окружения

> Секреты (`DATABASE_URL`, `*_API_KEY`) читаются ТОЛЬКО из окружения, не
> логируются и не попадают в `parser_runs`. Файл `.env` парсер не читает.

| Переменная | Тип | По умолч. | Обяз. | Назначение |
|---|---|---|---|---|
| `DATABASE_URL` | string | — | да | DSN PostgreSQL (напр. `postgres://user:pass@host:5432/db`). Секрет. |
| `SCRAPER_PROVIDER` | `firecrawl`\|`jina` | — | да | Провайдер HTML→Markdown. |
| `SCRAPER_API_KEY` | string | — | да | Ключ скрейпера. Секрет. (Для Jina опционален функционально, но валидация требует непустой.) |
| `AI_PROVIDER` | `gemini`\|`qwen`\|`openrouter` | — | да | AI-модель для извлечения. |
| `AI_API_KEY` | string | — | да | Ключ AI. Секрет. |
| `AI_MODEL` | string | по провайдеру | нет | Переопределение модели (дефолт: `gemini-1.5-flash` / `qwen-plus` / `google/gemini-2.5-flash`). |
| `PARSER_DEBUG_LOG` | bool | `false` | нет | `true` → запись метаданных каждой задачи в `parser_runs`. |
| `PARSER_CONCURRENCY` | int | `1` | нет | Максимум задач параллельно. |
| `PARSER_HTTP_TIMEOUT_SEC` | int | `60` | нет | Таймаут на скрейп одной страницы. |
| `PARSER_AI_TIMEOUT_SEC` | int | `120` | нет | Таймаут на один вызов AI. |

## Сборка и тесты (в Docker, `golang:1.23`)

Go локально не требуется. В образе:

```sh
go mod tidy      # заполнит go.sum контрольными суммами зависимостей
go build ./...
go test ./...
```

Запуск одного прогона (переменные окружения должны быть заданы извне):

```sh
go run ./cmd/parser
```

Крон-расписание задаётся снаружи (cron/systemd-timer/CI scheduler) — парсер сам
не планирует повторные запуски.
