# HANDOFF — состояние проекта Sravni.tj

Выжимка работы и точка продолжения. Дата: 2026-06.

---

## 1. Что это
Агрегатор банковских продуктов Таджикистана (аналог sravni.ru). Монорепо:
- `parser/` — Go: парсер продуктов (Jina → OpenRouter AI → PostgreSQL).
- `backend/` — Laravel 13 (PHP 8.4): REST API + приём заявок/отзывов.
- `frontend/` — Vue 3 + Vite + TS + Pinia + Vue I18n: витрина.
- `docker-compose.yml` + PostgreSQL 16.

См. также [ТЗ.md](../ТЗ.md) (раздел 12 — актуализация), [PRD.md](../PRD.md), [CLAUDE.md](../CLAUDE.md), [docs/db/schema.md](db/schema.md), [docs/api/contracts.md](api/contracts.md).

---

## 2. Как запустить (важно — окружение капризное)

**Окружение:** Windows + Docker Desktop. Локально: Node есть; PHP 7.4 (НЕ годится для Laravel 13 — всё через Docker); Go нет (только Docker).

**Запомнить:**
- **Docker Desktop часто не держится** — если `docker info` молчит, открой Docker Desktop вручную, дождись «Engine running».
- **Пароль БД = `secret`** (том инициализирован им; `.env` `change_me` игнорируется существующим томом). Для контейнерных подключений DSN: `postgres://sravni:secret@db:5432/sravni`.
- **Том `sravni_pgdata` постоянный** — данные переживают `stop`/`restart`. Стирает только `docker compose down -v`.
- **Тесты идут в отдельную БД `sravni_test`** (форсится в `phpunit.xml`). НЕ гонять тесты по dev — но даже если случайно, dev-данные теперь защищены.

### Поднять стек
```bash
cd D:/Projects/sravni
docker compose up -d db backend        # db + API :8000
```

### Миграции / сиды (dev, БЕЗ потери данных используем migrate, не migrate:fresh)
```bash
docker compose run --rm -T -e DB_HOST=db -e DB_PORT=5432 -e DB_DATABASE=sravni \
  -e DB_USERNAME=sravni -e DB_PASSWORD=secret backend php artisan migrate --force
# Первичное наполнение банками/источниками (идемпотентно):
docker compose run --rm -T -e DB_HOST=db ... backend php artisan db:seed --force
```

### Тесты (на sravni_test — НЕ передавать DB_DATABASE)
```bash
docker compose exec -T db psql -U sravni -d sravni -c "CREATE DATABASE sravni_test OWNER sravni;"  # один раз
docker compose run --rm -T -e DB_HOST=db -e DB_PORT=5432 -e DB_USERNAME=sravni -e DB_PASSWORD=secret \
  backend php artisan test
```

### Парсер (одноразовая задача, не сервис)
```bash
docker compose run --rm -T \
  -e DATABASE_URL="postgres://sravni:secret@db:5432/sravni?sslmode=disable" \
  -e SCRAPER_PROVIDER=jina -e SCRAPER_API_KEY="" \
  -e AI_MODEL="deepseek/deepseek-v3.2" \
  -e PARSER_DEBUG_LOG=true \
  parser go run ./cmd/parser
# Прогнать только часть банков: выставить is_active в bank_source_urls.
```

### Фронтенд (локально, НЕ контейнер)
```bash
cd D:/Projects/sravni/frontend
# Реальные данные с backend:
VITE_USE_MOCKS=false VITE_API_BASE_URL=http://localhost:8000/api npm run dev   # :5173
# Без флага VITE_USE_MOCKS=false по умолчанию идут МОКИ.
npm run build   # проверка типов (vue-tsc) + сборка
```

---

## 3. Что готово и проверено ✅

**БД/бэкенд (Laravel 13):** 18 банков, ~47 источников, схема (banks, bank_source_urls, products, product_rates, leads, parser_runs, bank_reviews). API: `GET /api/products` (фильтры: category, currency, **bank_id[]**, amount/term/rate, features[], sort; пагинация), `GET /api/products/{id}`, `GET /api/banks`, `GET/POST /api/banks/{id}/reviews`, `POST /api/leads`. Все feature-тесты зелёные.

**Парсер (Go):** pipeline Jina→AI→validate→upsert; index-режим (каталог→детали) с гибрид-подсказкой раздела меню; OpenRouter-экстрактор; nullable amount_min; подкатегории (subcategory). Build/vet/test зелёные.

**Данные:** распарсено **127 продуктов** (credit/deposit/installment) по ~9 банкам, 21/47 источников. Подтверждён сквозной цикл сайт→БД→API→витрина.

**Фронтенд:** каталог (карточки-строки + ★рейтинг + фильтры + мультиселект банков иконками), карточка продукта, сравнение (кнопки прижаты к низу), форма заявки, i18n ru/tg, калькулятор. `npm run build` зелёный.

**Рейтинги/отзывы:** бэкенд готов (премодерация, агрегат рейтинга в каталоге). Демо-отзывов ещё нет → везде «Нет оценок».

---

## 4. НЕЗАВЕРШЁННОЕ (продолжить здесь) ⬜

### 4.1. Подкатегории — ✅ ГОТОВО (backend + frontend + контракт)
- **backend:** ✅ `subcategory` отдаётся в `ProductResource`; фильтр `subcategory[]` (ProductIndexRequest валидирует 13 кодов → 422 на мусор; ProductController whereIn). Тесты: `test_filters_by_subcategory_multiselect`, `test_invalid_subcategory_returns_422` (36 тестов зелёные).
- **frontend:** ✅ тип `Subcategory` + поле в `Product`/`ProductQuery`; бейдж на карточке (`ProductCard`); фильтр-чипы в `CatalogFilters` (зависят от категории: credit/deposit, у installment нет); `useCatalogQuery` парс/сериализация `subcategory[]`; моки (фикстуры + хендлер); i18n ru/tg для всех 13 кодов + лейбл фильтра. `npm run build` зелёный.
- **контракт:** ✅ `docs/api/contracts.md` — поле в схеме Product, query-параметр, таблица кодов по категориям, примеры.
- ⬜ **перепарс (осталось):** у текущих 127 продуктов `subcategory=NULL` — заполнится при следующем прогоне парсера (см. 4.5).

### 4.2. Курсы валют по банкам (новая фича, не начата)
- Таблица `bank_currency_rates` (bank_id, currency, buy, sell, rate_date) + миграция.
- Новый тип источника + парс курсов с сайтов банков (расширить парсер).
- API + виджет на главной/в каталоге.

### 4.3. Редизайн остальных страниц (каталог+сравнение готовы)
- **Главная** (`/` сейчас редиректит на `/credit`) — сделать лендинг: плитки Кредиты / Депозиты / Курсы валют / Оценка банков.
- **Карточка продукта**, **шапка/футер**, **форма заявки** — освежить под новый стиль.
- Страница **«Оценка банков»** (список банков со ★ + переход к отзывам).

### 4.4. Отзывы — фронтенд
- Форма «Оставить отзыв» (POST на готовый эндпоинт) + страница отзывов банка.
- Засеять демо-approved-отзывы, чтобы ★ ожили.

### 4.5. Полный прогон парсера
- С ключом **DeepSeek** (`AI_MODEL=deepseek/deepseek-v3.2`, баланс на OpenRouter) прогнать все 47 источников (free-tier не вывозит: лимит/429/обрезки).
- Догнать непарсенные банки. Проблемные страницы-каталоги решаются index-режимом; alif `salom`/`alifshop` отложены (`is_active=false`).

### 4.6. Мелочи
- **`.env` deny-правило** так и не применено: auto-mode блокирует правку `settings.local.json`. Нужно вручную добавить в `permissions.deny`: `Read/Edit/Write(**/.env)` и `**/.env.*`.
- Удалить мусорные скриншоты в корне (`sravni-*.png`, `our-catalog.png`).
- `frontend/src/components/ui/BaseMultiSelect.vue` — больше не используется (заменён на `BankPicker.vue`), можно удалить.

---

## 5. Ключевые «грабли» (gotchas)
- Laravel 13 тянет symfony 8.1 → нужен **PHP 8.4** (в Dockerfile). Free-модели OpenRouter: `:free`-id часто мертвы/лимитированы.
- `php artisan test` с `RefreshDatabase` делает `migrate:fresh` — раньше стёр dev-данные; теперь изолировано на `sravni_test` (phpunit.xml, `force`).
- Парсер: модель должна поддерживать **structured outputs** (strict json_schema). DeepSeek/Gemini-flash/gpt-4o-mini — да.
- Подкатегория subcategory: новая миграция `2026_06_05_000008_*` (ALTER, не fresh) — применять `migrate`.
