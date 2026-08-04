# Деплой Sravni.tj в Railway

Монорепо разворачивается как **6 сервисов** в одном Railway-проекте:

| Сервис | Root Directory | Builder | Назначение |
|---|---|---|---|
| **Postgres** | — (плагин) | — | БД (общая для backend и parser) |
| **backend** | `backend` | Dockerfile | Laravel REST API + админ API |
| **frontend** | `frontend` | Nixpacks | Vue SPA (витрина + админка) |
| **chrome** | — (Docker Image) | — | Свой headless-Chrome для scraper='browser', always-on |
| **parser** | `parser` | Dockerfile (cron) | Go-парсер (discover+parser), раз в сутки |
| **parser-rates** | `parser` | Dockerfile (cron) | Курсы валют, раз в час, 08:00–18:00 |

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

## 3. frontend (Vue SSR)

Root Directory: `frontend`. Builder: **Dockerfile** ([railway.json](../frontend/railway.json) → [Dockerfile.railway](../frontend/Dockerfile.railway)), не Nixpacks. Сборка гоняет `npm run build` (это `vue-tsc -b && vite build && vite build --ssr src/entry-server.ts` — клиентский бандл в `dist/client`, серверный в `dist/server`), рантайм — живой Node-процесс `node server/index.js` (Express: `vue/server-renderer` рендерит HTML на каждый запрос, статика отдаётся из `dist/client`). Это не статик-хостинг и не `vite preview` — каталог/курсы/продукты рендерятся с реальными данными из backend API на сервере, что и даёт краулерам (и AI-ботам без JS) полноценный HTML вместо пустого `#app`.

`/admin/*` — единственное исключение: отдаётся статический CSR-шелл без SSR (авторизация админки живёт в `localStorage` браузера, на сервере её не видно — SSR всегда показал бы "разлогинен").

### Переменные frontend — build-time vs runtime (важное разделение)

**Build-time** (`ARG`/`ENV` в Dockerfile, вшиваются в бандл при `npm run build`, видны и клиенту, и серверу через `import.meta.env`):

```
VITE_API_BASE_URL=https://<backend-домен>.up.railway.app/api   # публичный домен backend — для fetch из браузера после гидратации
VITE_USE_MOCKS=false
VITE_PUBLIC_SITE_ORIGIN=https://sravni.tj                       # для абсолютных canonical/hreflang/sitemap URL
```

Меняются только через **пересборку** (Railway → frontend → Settings → Variables, но это build args, не runtime env — их значения фиксируются в момент `npm run build` и переопределить их после билда без ребилда нельзя).

**Runtime** (обычные Railway-переменные сервиса, читаются `process.env` живым Node-процессом на каждый запрос — можно менять и рестартовать без пересборки):

```
SSR_API_BASE_URL=http://backend.railway.internal:<port>/api    # приватный Railway-домен backend — server-to-server, без публичного интернета и CORS
```

Уточните точный приватный хостнейм/порт backend в Railway dashboard → сервис backend → вкладка Networking (приватный домен вида `<service>.railway.internal`, порт — тот же, что слушает `php artisan serve`). Без `SSR_API_BASE_URL` сервер молча упадёт на `VITE_API_BASE_URL` (публичный домен) — рабочий фолбэк, но лишний круг через публичный интернет вместо приватной сети.

### Rollout

Runtime-модель сменилась (статик-раздача → живой Node-процесс) — это не косметическое изменение. Перед промоутом на прод:
1. Задеплоить на отдельное Railway-окружение/превью с теми же `VITE_API_BASE_URL`/`SSR_API_BASE_URL`, указывающими на прод-backend.
2. Смоук-тест: `/`, `/tj`, `/credit`, `/tj/credit`, `/product/:id`, `/bank/:id`, `/kurs-valyut`, `/otzyvy`, `/admin` (должен остаться логин-форма CSR), `/robots.txt`, `/sitemap.xml`.
3. Проверить `view-source:` на реальных URL — HTML не должен быть пустым `#app`.
4. Только после этого — промоут. Предыдущий образ (статик-`serve`) остаётся в истории деплоев Railway для отката одним кликом.

---

## 4. chrome (свой скрейпер, always-on)

Свой headless-Chrome для источников со `scraper='browser'` (замена платному
Firecrawl — cron-сервисы `parser`/`parser-rates` разовые, а рендерить страницы
им нужно КАЖДЫЙ прогон, поэтому Chrome — отдельный **always-on** сервис, не
cron, к которому они стучатся по CDP через приватную сеть Railway).

Добавить сервис: **Deploy from Docker Image**, образ `chromedp/headless-shell:stable`.
Root Directory не нужен (не собирается из репозитория). Публичный домен не
нужен — доступ только по внутренней сети Railway (`chrome.railway.internal:9222`).

---

## 5. parser (Go, cron)

Root Directory: `parser`. Сборка по [Dockerfile.railway](../parser/Dockerfile.railway) (отдельный от dev-`Dockerfile`, который используется только в docker-compose): собирает `cmd/discover` и `cmd/parser`, запускает их последовательно через [run.sh](../parser/run.sh) — **discover сначала**, чтобы наполнить `bank_source_urls` НОВЫМИ страницами продуктов, затем `parser` их читает. Расписание `0 3 * * *` (ежедневно 03:00 UTC), `restartPolicyType: NEVER` (разовый прогон).

> Cron-прогоны видны в отдельной вкладке **Cron Runs** сервиса в Railway, НЕ в Deployments (там только билды по git push). Логи конкретного прогона — Cron Runs → выбрать запуск → Deploy Logs.

> `PARSER_CONCURRENCY` — общий лимит на ОБА уровня параллелизма: между задачами (`bank_source_urls`) и внутри задачи (детальные страницы index-режима). Значение `1` означает полностью последовательную обработку — для 18 банков это часы. На проде ставьте 3–5 (упирается в rate-limit AI-провайдера, не в CPU).

### Переменные окружения parser

```
DATABASE_URL=${{Postgres.DATABASE_URL}}
SCRAPER_API_KEY=
BROWSER_CDP_URL=http://chrome.railway.internal:9222
AI_PROVIDER=openrouter
AI_API_KEY=
AI_MODEL=
PARSER_DEBUG_LOG=false
PARSER_CONCURRENCY=3
```

> Скрейпер выбирается ПЕР-ИСТОЧНИК, не этой переменной: по умолчанию свой
> скрейпер (прямой HTTP GET, бесплатно), `bank_source_urls.scraper` /
> `bank_parse_instructions.scraper` = `browser` (свой headless Chrome, сервис
> **chrome** выше) или `firecrawl` (платный фолбэк) — для банков с
> client-rendered JS (Angular/React SPA) или anti-bot защитой, которую свой
> скрейпер не проходит (курируется вручную в сидерах). `SCRAPER_API_KEY`
> нужен, только если остались источники с `firecrawl`.

---

## 6. parser-rates (Go, cron)

Отдельный сервис, но **тот же репозиторий и тот же Root Directory** — `parser`. Собирает только `cmd/rates` по [Dockerfile.rates.railway](../parser/Dockerfile.rates.railway) и запускает [run-rates.sh](../parser/run-rates.sh). Курс валют меняется чаще, чем продукты, и только в рабочие часы банков — крон гоняет `rates` **раз в час, 08:00–18:00 по Душанбе (UTC+5)**.

Т.к. Railway cron задаётся в **UTC**, окно 08:00–18:00 TJT = 03:00–13:00 UTC → `cronSchedule: "0 3-13 * * *"` ([railway.rates.json](../parser/railway.rates.json)), `restartPolicyType: NEVER`.

При создании сервиса в дашборде Railway: Root Directory = `parser` (как у основного parser), но в **Settings → Config-as-code Path** указать `railway.rates.json` (по умолчанию Railway ищет `railway.json` — так оба сервиса из одной папки не конфликтуют).

### Переменные окружения parser-rates

Те же, что у `parser` (`DATABASE_URL`, `SCRAPER_API_KEY`, `BROWSER_CDP_URL`, `AI_PROVIDER`, `AI_API_KEY`, `AI_MODEL`, `PARSER_DEBUG_LOG`) — `rates` использует тот же скрейпер/AI-конфиг. `PARSER_CONCURRENCY` не используется (`rates` обходит банки последовательно).

---

## 7. Telegram-бот и уведомления о курсе

Добавляет к сервису **backend** (§2) новые переменные и один разовый шаг после первого деплоя. Никакого нового Railway-сервиса не требуется — webhook принимает тот же Laravel-процесс.

### Переменные окружения backend (добавить к §2)

```
TELEGRAM_BOT_TOKEN=              # токен бота от @BotFather
TELEGRAM_BOT_USERNAME=           # без @, напр. sravni_bot
TELEGRAM_CHANNEL_INVITE_LINK=    # ссылка на канал для инвайта (2-е сообщение бота); пусто → инвайт не шлётся
TELEGRAM_WEBHOOK_SECRET=         # случайная строка — сверяется с X-Telegram-Bot-Api-Secret-Token
TELEGRAM_RATES_WEBHOOK_SECRET=   # случайная строка — сверяется с X-Internal-Secret (вызов от parser-rates)
FRONTEND_URL=https://sravni.tj   # используется в ссылке на профиль в приветственном сообщении бота
```

### Разовый шаг: регистрация webhook

После деплоя backend (уже после того, как у него есть публичный домен) — через Railway shell:

```
php artisan telegram:set-webhook
```

Без аргумента берёт `APP_URL` + `/api/telegram/webhook`. Проверить ответ `{"ok":true,...}`.

### parser-rates → backend (§6)

Добавить к переменным `parser-rates` (не влияет на основной `parser`):

```
# Предпочтительно приватный Railway-домен backend (server-to-server, без выхода
# в публичный интернет) — как SSR_API_BASE_URL в §3. Порт — тот, что слушает
# php artisan serve. Публичный https-адрес тоже сработает как фолбэк.
BACKEND_RATES_WEBHOOK_URL=http://backend.railway.internal:<port>/api/internal/rates-notify
BACKEND_RATES_WEBHOOK_SECRET=    # то же значение, что TELEGRAM_RATES_WEBHOOK_SECRET у backend
```

> `DispatchRateAlerts` работает при `QUEUE_CONNECTION=sync` (§2) — Job выполняется
> синхронно внутри запроса `rates-notify`, отдельный воркер `queue:work` НЕ нужен.
> Парсер зовёт эндпоинт best-effort с коротким таймаутом; если рассылка дольше —
> парсер не ждёт, но Laravel её завершит.

Best-effort вызов — при сбое сети/backend прогон курсов не падает, только пишет warning в лог.

### Очередь рассылки

`DispatchRateAlerts` — queued Job (`ShouldQueue`). Текущий `QUEUE_CONNECTION=sync` (§2) означает, что при `sync` job выполняется **синхронно внутри запроса** `/api/internal/rates-notify` — рабочий вариант для низкой нагрузки (десятки подписок), но блокирует ответ на время рассылки. Для async-обработки: переключить `QUEUE_CONNECTION=database` и запустить воркер (`php artisan queue:work --daemon`) — отдельным процессом/сервисом, аналогично cron-сервисам выше. Не блокер запуска фичи, но пункт для дальнейшего масштабирования.

---

## Порядок деплоя

1. Создать проект, добавить **PostgreSQL**.
2. Добавить сервис **backend** из репо, Root = `backend`, задать env (см. выше), сгенерировать `APP_KEY`. Дождаться деплоя; разово выполнить `php artisan db:seed --force` (Railway → service → shell).
3. Добавить сервис **frontend**, Root = `frontend`, задать build-переменные `VITE_API_BASE_URL` (публичный домен backend) + `VITE_USE_MOCKS=false` + `VITE_PUBLIC_SITE_ORIGIN`, и runtime-переменную `SSR_API_BASE_URL` (приватный `backend.railway.internal` — см. §3).
4. Добавить сервис **chrome** — Deploy from Docker Image `chromedp/headless-shell:stable`, без Root Directory и без публичного домена.
5. Добавить сервис **parser**, Root = `parser`, задать env (включая `BROWSER_CDP_URL=http://chrome.railway.internal:9222`). Проверить, что Railway распознал cron.
6. Добавить сервис **parser-rates**, Root = `parser`, Config-as-code Path = `railway.rates.json`, задать те же env. Проверить cron (`0 3-13 * * *` UTC).
7. Открыть домен frontend → витрина; `/admin` → вход админки.

## Заметки

- `php artisan serve` — встроенный однопоточный сервер. Для MVP/низкого трафика достаточно; при росте нагрузки заменить на php-fpm + nginx или FrankenPHP.
- Приоритет данных админки над парсером (категории/метки) обеспечен на уровне БД (`products.locked_fields`) — деплой парсера это не ломает.
- CORS: публичный API отдаётся на другом домене, чем SPA. Конфиг `config/cors.php` по умолчанию разрешает `*` для `api/*`; при необходимости сузьте до домена frontend.
