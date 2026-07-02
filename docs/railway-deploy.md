# Деплой Sravni.tj в Railway

Монорепо разворачивается как **4 сервиса** в одном Railway-проекте:

| Сервис | Root Directory | Builder | Назначение |
|---|---|---|---|
| **Postgres** | — (плагин) | — | БД (общая для backend и parser) |
| **backend** | `backend` | Dockerfile | Laravel REST API + админ API |
| **frontend** | `frontend` | Nixpacks | Vue SPA (витрина + админка) |
| **parser** | `parser` | Dockerfile (cron) | Go-парсер, по расписанию |

Каждый сервис конфигурируется своим `railway.json` (в корне его директории). В дашборде Railway у каждого сервиса задаётся **Root Directory** = соответствующая папка — тогда Railway найдёт нужный `railway.json`.

---

## 1. Postgres

Add → **Database → PostgreSQL**. Railway выдаёт переменные подключения и `DATABASE_URL`. На них ссылаются backend и parser через `${{Postgres.VARIABLE}}`.

---

## 2. backend (Laravel)

Root Directory: `backend`. Сборка по [Dockerfile](../backend/Dockerfile) (php 8.4 + pdo_pgsql, composer install). Старт ([railway.json](../backend/railway.json)):

```
php artisan migrate --force && php artisan serve --host 0.0.0.0 --port ${PORT}
```

Healthcheck: `/up`.

### Переменные окружения backend

```
APP_NAME=Sravni
APP_ENV=production
APP_KEY=            # сгенерировать: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://<backend-домен>.up.railway.app

# БД — ссылки на сервис Postgres
DB_CONNECTION=pgsql
DB_HOST=${{Postgres.PGHOST}}
DB_PORT=${{Postgres.PGPORT}}
DB_DATABASE=${{Postgres.PGDATABASE}}
DB_USERNAME=${{Postgres.PGUSER}}
DB_PASSWORD=${{Postgres.PGPASSWORD}}

# Заявки уходят письмом синхронно (без отдельного воркера очереди)
QUEUE_CONNECTION=sync

# SMTP для доставки заявок банкам (banks.email / bank_source_urls.email)
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=no-reply@sravni.tj
MAIL_FROM_NAME=Sravni.tj
```

> `APP_KEY` обязателен. Сгенерируйте локально `php artisan key:generate --show` и вставьте значение (`base64:...`).

После первого деплоя миграции + сидеры применятся автоматически (`migrate --force` в startCommand). Сидер заводит админа `admin@sravni.tj` / `admin12345` — **смените пароль** в разделе «Пользователи».

> Сидеры запускаются только если вызвать `--seed`. В startCommand стоит чистый `migrate`. Однократно посейте через Railway shell: `php artisan db:seed --force` (или добавьте `--seed` к первому деплою и затем уберите).

---

## 3. frontend (Vue SPA)

Root Directory: `frontend`. Nixpacks ([railway.json](../frontend/railway.json)): `npm ci && npm run build`, старт `vite preview` на `$PORT`.

### Build-переменные frontend (важно: вшиваются в бандл на этапе сборки)

```
VITE_API_BASE_URL=https://<backend-домен>.up.railway.app/api
VITE_USE_MOCKS=false
```

`VITE_*` читаются во время `npm run build`, поэтому задайте их до деплоя. При смене домена backend — пересоберите frontend.

SPA-роутинг (`/admin`, `/product/:id`) работает: `vite preview` отдаёт `index.html` как fallback. `allowedHosts: true` в [vite.config.ts](../frontend/vite.config.ts) разрешает Railway-домен.

---

## 4. parser (Go, cron)

Root Directory: `parser`. Сборка по [Dockerfile.railway](../parser/Dockerfile.railway) (отдельный от dev-`Dockerfile`, который используется только в docker-compose): собирает все три бинарника (`cmd/discover`, `cmd/parser`, `cmd/rates`) и запускает их последовательно через [run.sh](../parser/run.sh) — **discover сначала**, чтобы наполнить `bank_source_urls` НОВЫМИ страницами продуктов, затем `parser` их читает, затем `rates`. Расписание `0 3 * * *` (ежедневно 03:00 UTC), `restartPolicyType: NEVER` (разовый прогон).

> Cron-прогоны видны в отдельной вкладке **Cron Runs** сервиса в Railway, НЕ в Deployments (там только билды по git push). Логи конкретного прогона — Cron Runs → выбрать запуск → Deploy Logs.

> `PARSER_CONCURRENCY` — общий лимит на ОБА уровня параллелизма: между задачами (`bank_source_urls`) и внутри задачи (детальные страницы index-режима). Значение `1` означает полностью последовательную обработку — для 18 банков это часы. На проде ставьте 3–5 (упирается в rate-limit AI-провайдера, не в CPU).

### Переменные окружения parser

```
DATABASE_URL=${{Postgres.DATABASE_URL}}
SCRAPER_PROVIDER=firecrawl
SCRAPER_API_KEY=
AI_PROVIDER=openrouter
AI_API_KEY=
AI_MODEL=
PARSER_DEBUG_LOG=false
PARSER_CONCURRENCY=3
```

---

## Порядок деплоя

1. Создать проект, добавить **PostgreSQL**.
2. Добавить сервис **backend** из репо, Root = `backend`, задать env (см. выше), сгенерировать `APP_KEY`. Дождаться деплоя; разово выполнить `php artisan db:seed --force` (Railway → service → shell).
3. Добавить сервис **frontend**, Root = `frontend`, задать `VITE_API_BASE_URL` (домен backend) + `VITE_USE_MOCKS=false`.
4. Добавить сервис **parser**, Root = `parser`, задать env. Проверить, что Railway распознал cron.
5. Открыть домен frontend → витрина; `/admin` → вход админки.

## Заметки

- `php artisan serve` — встроенный однопоточный сервер. Для MVP/низкого трафика достаточно; при росте нагрузки заменить на php-fpm + nginx или FrankenPHP.
- Приоритет данных админки над парсером (категории/метки) обеспечен на уровне БД (`products.locked_fields`) — деплой парсера это не ломает.
- CORS: публичный API отдаётся на другом домене, чем SPA. Конфиг `config/cors.php` по умолчанию разрешает `*` для `api/*`; при необходимости сузьте до домена frontend.
