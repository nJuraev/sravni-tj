# Миграции БД — Sravni.tj (backend)

Подготовленные Laravel-миграции по утверждённой схеме PostgreSQL
(см. [`docs/db/schema.md`](../../../docs/db/schema.md) и
[`docs/parser/ai-output-schema.md`](../../../docs/parser/ai-output-schema.md)).

> **Важно:** полноценный Laravel-проект ещё НЕ инициализирован — `composer install`
> не запускался, нет `composer.json`, `artisan`, `vendor/`, `config/`, `.env`.
> Это **только подготовленные файлы миграций** (фундамент схемы данных).
> Чтобы их запустить, сначала нужно создать/подключить Laravel-приложение в `backend/`.

## Предпосылки

- **Laravel 11+** (синтаксис анонимных классов миграций `return new class extends Migration`).
- **PostgreSQL 15+** как СУБД (`DB_CONNECTION=pgsql` в `.env`).
- Используются Postgres-специфичные возможности: `jsonb`, GIN-индекс
  (`jsonb_path_ops`), частичный индекс (`WHERE is_active = true`), `CHECK`-ограничения,
  `TIMESTAMPTZ`. На MySQL/SQLite миграции в текущем виде не пройдут.

## Запуск (после инициализации Laravel)

```bash
# из директории backend/
php artisan migrate          # применить все миграции
php artisan migrate:rollback # откатить последний батч
php artisan migrate:fresh    # пересоздать схему с нуля (drop all + migrate)
```

## Порядок таблиц (учитывает FK-зависимости)

Временные метки в именах файлов строго возрастают, поэтому Laravel применит
миграции в правильном порядке:

| # | Файл | Таблица | Зависит от |
|---|------|---------|-----------|
| 1 | `2026_06_05_000001_create_banks_table.php` | `banks` | — |
| 2 | `2026_06_05_000002_create_bank_source_urls_table.php` | `bank_source_urls` | `banks` |
| 3 | `2026_06_05_000003_create_products_table.php` | `products` | `banks`, `bank_source_urls` |
| 4 | `2026_06_05_000004_create_product_rates_table.php` | `product_rates` | `products` |
| 5 | `2026_06_05_000005_create_leads_table.php` | `leads` | `products`, `banks` |
| 6 | `2026_06_05_000006_create_parser_runs_table.php` | `parser_runs` | `bank_source_urls` |

## Конвенции в миграциях

- **Enum-поля** (`category`, `currency`, статусы) — `VARCHAR` + `CHECK`-ограничение
  через `DB::statement`, а не нативный Postgres `ENUM` (проще эволюция значений).
  CHECK снимаются в `down()` через `DROP CONSTRAINT IF EXISTS`.
- **Деньги/ставки** — `NUMERIC` (`decimal`): `rate*` = `NUMERIC(6,3)`,
  суммы = `NUMERIC(18,2)`. Никаких `float`.
- **`features`** — `jsonb` с GIN-индексом (`jsonb_path_ops`) под `@>`-фильтры.
- **Тарифная сетка** — отдельная таблица `product_rates` (Вариант A) +
  денормализованные `products.rate_min/rate_max` под B-tree индексами.
- **Временные метки** — `TIMESTAMPTZ` (`timestampsTz` / `timestampTz`).
- **FK** через `foreignId()->constrained()` с явным `onDelete`
  (`cascade` / `nullOnDelete` / `restrictOnDelete`).

## Утверждённые решения, отражённые в схеме

- `products.status` **DEFAULT `'draft'`** (не `'active'`).
- Один продукт = одна валюта (`products.currency`); отдельной таблицы валют нет.
- `leads.consent` `BOOLEAN` без DEFAULT + `CHECK (consent = true)`.
- `leads.bank_id` `ON DELETE RESTRICT`; `leads.product_id` `ON DELETE SET NULL`.
- Идемпотентность парсера: `UNIQUE (source_url_id, external_key)` на `products`.
