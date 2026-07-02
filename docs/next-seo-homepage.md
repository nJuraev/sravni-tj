# Sravni.tj — хендофф: новая главная + SEO/GEO

Точка входа для нового чата. Всё, что сделано до этапа «переделка главной + SEO/GEO», и что делать дальше.

---

## Что уже сделано (готово, задеплоено)

### Админка (`/admin`)
- `admin_users` (миграция + сидер), auth через token-guard Laravel (guard `admin`, `Authorization: Bearer`).
- CRUD: банки, продукты (+ быстрый toggle active↔hidden), лиды (просмотр/удаление), пользователи (роль `admin`/`editor`).
- Backend: `app/Http/Controllers/Admin/*`, ресурсы `app/Http/Resources/Admin/*`, роуты `/api/admin/*`.
- Frontend: `src/views/admin/*`, `src/stores/admin.ts`, `src/api/admin.ts`, `src/assets/styles/admin.css`.
- Дефолт-админ: `admin@sravni.tj` / `admin12345` — **сменить пароль**.
- Протестировано Playwright: логин, банки, детально банка+продукты, toggle, редактирование, пользователи.

### Приоритет админки над парсером (инвариант)
- `products.locked_fields` (jsonb). Админ при сохранении продукта лочит `category`/`subcategory`/`features`.
- Парсер (`parser/internal/store/pg.go`, `UpsertProduct`) не перетирает залоченные поля; метки (`features`) объединяет (union, админ побеждает), новые добавляются.
- Доказано SQL-тестом на живой БД. UI-бейдж «закреплено» в списке продуктов банка.

### Деплой Railway (работает)
- Monorepo, 4 сервиса: Postgres + backend + frontend + parser, каждый с Root Directory.
- **backend**: Dockerfile, `CMD` = `migrate --force && artisan serve --port ${PORT}`. Порт домена 8081, переменная `PORT=8081`. Healthcheck `/up`.
- **frontend**: `Dockerfile.railway` (multi-stage: `npm ci` + `vite build` → `serve -s dist -l ${PORT}`). `VITE_API_BASE_URL`, `VITE_USE_MOCKS=false` как build args.
- **parser**: Nixpacks, cron `0 3 * * *`, env `DATABASE_URL=${{Postgres.DATABASE_URL}}` + SCRAPER/AI ключи.
- Конфиги: `backend/railway.json`, `frontend/railway.json`, `parser/railway.json`. Гайд: `docs/railway-deploy.md`.
- GitHub: `github.com/nJuraev/sravni-tj` (main). `.env`/`node_modules`/`vendor`/`dist` — в .gitignore.

### Грабли деплоя (на будущее)
- Railway startCommand **не раскрывает** `${PORT}` — держать запуск в Dockerfile `CMD` (`sh -c`) или npm-скрипте.
- `npx vite preview` на Railway запускается из кэша npx, игнорит `vite.config` → 502. Решено через `serve` в Dockerfile.
- Монорепо: доп. сервисы — через **Empty Service → Connect Repo** (пикер «repository not found» = ввод полного URL/.git; надо имя `sravni-tj`).
- `VITE_*` вшиваются при сборке → менять → **Redeploy** фронта.

---

## SEO/GEO research (deep-research, 2026-06-30) — ключевое

Верификация упала на rate-limit, но факты из первичных источников (NBT, schema.org, vuejs.org) надёжны. Полное — в памяти проекта `sravni-seo-competitors.md`.

**Конкуренты:**
- `banki.tj` — прямой конкурент-агрегатор, но **фактически заброшен** (© 2019, устаревшие ставки, без schema.org). → Sravni.tj = первый АКТУАЛЬНЫЙ агрегатор.
- `kurs.tj`/`valuta.tj`/`themoney.tj` — живые, но **только курсы**, не продукты.
- `aion.tj` — кредитный лендинг («кредит наличными без залога и справок»).
- `nbt.tj` — источник-авторитет курсов (машиночитаемые форматы), цитировать.

**Дифференциация:** «курс + кредит + депозит» в одном, актуально, ru+tg, со structured data — этого нет ни у кого.

**Приоритеты (что делать в первую очередь):**
1. **Prerender/SSG** для Vue SPA (`vite-ssg`) — блокер №1, SPA не индексируется (пустой HTML-shell).
2. Страницы курсов (highest volume) + `ExchangeRateSpecification` JSON-LD + виджет курса на главной.
3. Новая главная: курс (крючок) → кредиты (лид) → депозиты.
4. Кредитные посадочные + `LoanOrCredit` JSON-LD.
5. hreflang ru/tg (reciprocal, self-ref, x-default, в head через SSG/sitemap), sitemap, mobile-first.
6. GEO: Q&A-блоки, заголовки-вопросы, цифры + ссылка на НБТ; серверный JSON-LD (`Organization`, `FAQPage`).

**Посадочные под SEO:** `/kurs-valyut` (+ по валютам), `/kredity` (+ модификаторы), `/vklady`, `/bank/<slug>` (+ `/otzyvy`). Короткие описания 300-500 симв, ассортимент важнее текста.

**Отзывы:** держим инфру, показываем рейтинг только при ≥3 (cold-start). Не на главную. См. память `sravni-reviews-policy.md`.

---

## Текущий этап (продолжить здесь)

**Согласовано:** новая главная (курс > кредит > депозит, с CTA) + сайт под SEO/GEO.

**План (5 скиллов, только установленные):**
1. `deep-research` — SEO/GEO стратегия + карта запросов ✅ **сделано** (см. выше + память).
2. `to-prd` — ТЗ на новую главную (структура первого экрана, иерархия CTA, блоки по популярности).
3. `frontend-design` — макет главной (герой с курсом, палитра eskhata `#0050C8`).
4. `frontend-ui-engineering` — реализация Vue + техническое SEO (meta, OG, JSON-LD, hreflang, sitemap).
5. `performance-optimization` — Core Web Vitals + prerender/SSG (иначе SEO на SPA не взлетит).

**Следующий шаг:** research (#1) уже готов → начинать с **`to-prd`** (#2) — зафиксировать ТЗ новой главной, затем дизайн → код → prerender.

**Открытый вопрос для нового чата:** запускать `to-prd` под новую главную сейчас, или сразу техбазу (prerender/SSG + hreflang + JSON-LD)?

---

## Полезные указатели
- Стек/правила: `CLAUDE.md`.
- Деплой: `docs/railway-deploy.md`.
- Память проекта: `sravni-seo-competitors.md`, `sravni-reviews-policy.md` (в `~/.claude/.../memory/`).
- API курсов уже есть: `GET /api/rates`, `/api/rates/best`. Продукты: `/api/products/{credits,deposits,installments}`.
