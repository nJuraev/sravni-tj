# Снижение расхода токенов парсера — выжимка сессии

Контекст: расход на DeepSeek API вырос (~$1.75/день), разбирались почему и чинили.
Этот файл — саммари для продолжения в новом чате. Ссылки на код/строки могли
сместиться после дальнейших правок — сверяйся с реальным файлом, не только с этим.

## Статус git

- Закоммичено (`5c21412`, `a2b9eb5`): дедуп повторных строк, `_en`-стрип у Арванда,
  DeepSeek-схема-текст, header/footer/nav-вырез, IBT `faq-section`/`cform`-вырез.
- **НЕ закоммичено** (рабочее дерево): вся часть B (skip-if-unchanged кэш) +
  удаление auto-fallback в `cmd/parser`. Файлы:
  `parser/internal/extract/extract.go`, `parser/internal/model/model.go`,
  `parser/internal/parser/{arraysplit,parser,parser_test,cache}.go`,
  `parser/internal/store/{pg,store}.go`,
  `backend/database/migrations/2026_08_16_000001_add_content_hash_to_products_and_sources.php`.
- Миграция уже прогнана на локальном dev docker-compose (`php artisan migrate`),
  но **не прогнана на проде** — не забыть перед деплоем.
- Прямые `UPDATE` в БД (не миграции, применены только к dev): `bank_source_urls.scraper`
  и `bank_parse_instructions.scraper` = `'browser'` для oriyonbank (id 16,17) и
  dbt (id 22,23,26,27) — см. "Находки 2,3" ниже. **Эти же UPDATE нужно повторить
  на проде.**

---

## Часть 1 — почему рос расход (диагностика)

1. **DeepSeek не получал JSON Schema вообще.** Комментарий в коде врал, что "схема
   задаётся текстом промпта" — на деле нет. Фикс: `extract.PromptVersion` +
   `schemaPromptText()`/`stripSchemaDescriptions()` в `deepseek.go` — теперь схема
   (без задвоенных `description`, они и так есть в systemPrompt) реально уходит в
   промпт DeepSeek. Вероятная причина бага "ставка=0" у Арванда (`interest_rate:
   "31% - 32%"` без схемы rate_tiers модель не понимала, куда класть текстовый диапазон).
2. **Markdown, уходящий в AI, на 20-90% шум**: повторяющееся меню (шапка+моб.меню+
   sitemap в футере — дублируются буквально), `<header>/<footer>/<nav>` (даже без
   дублирования — просто шум по HTML5-семантике), FAQ/форма заявки (IBT: `faq-section`/
   `cform` по id), `_en`-поля у Арванда (не нужны, только ru/tg).
3. **Все фиксы шума — универсальные regex в `parser/internal/scrape/direct.go`**
   (`reCut`, `reNoiseBlocks`, `reHeader/reFooter/reNav`, `dedupLines`) — no-op там,
   где не применимо, без риска (в отличие от css_hints/CSS-селекторов — сознательно
   не стали делать, см. `docs/specs/parser.md:86` про хрупкость к вёрстке).

### Замеры до/после (реальные прогоны, `htmlToText` на живых страницах)

| Банк | Было | После дедупа | +header/footer/nav |
|---|---|---|---|
| eskhata (car) | 14520 | 10823 (-25%) | — |
| humo (дискавери) | — | 3142 | 2187 (-30%) |
| imon (продукт) | — | 4473 | 2856 (-36%) |
| spitamenbank (каталог) | — | 4564 | 3180 (-30%) |
| alif (продукт) | — | 6593 | 4934 (-25%) |
| IBT (5-продуктовая, `kredit-na-neobkhodimye-nuzhdy`) | — | 11676 | 9278 (-20.5%, `faq-section`/`cform`) |

**Spitamenbank — важное исключение:** FAQ-блок там (`sb-question`/`sb-item`)
содержит первым пунктом «Необходимые документы» — реальные данные для
`documents_ru`. Резать весь блок нельзя (в отличие от IBT, где FAQ — чистый шум).
**Не тронуто сознательно.**

---

## Часть 2 — Skip-if-unchanged кэш (главная тема сессии)

Идея: если текст, уходящий в AI, побайтово не изменился с прошлого успешного
прогона — не звать AI вообще, только подтвердить `parsed_at`.

### Архитектурное решение: Postgres, не файлы

Пользователь предлагал `.txt`-файлы на диске. Проверил `docs/railway-deploy.md` +
`parser/railway.json` — у parser-сервиса на Railway **нет persistent volume**,
`restartPolicyType: NEVER`, каждый крон-тик — новый контейнер. Файлы не
переживут прод. Кэш — в Postgres, 2 новые nullable-колонки:

- `bank_source_urls.last_markdown_hash` — хэш верхнеуровневой страницы задачи.
- `products.content_hash` — хэш текста конкретного продукта (для array-split —
  текста конкретного элемента массива).
- Новый индекс `idx_products_source_url_lookup` на `(source_url_id, source_url)`.

### Версия промпта в хэше (критично!)

`contentHash()` в `parser/internal/parser/cache.go` хэширует
`extract.PromptVersion + "|" + текст`, не просто текст. **Без этого** — если
поменяли systemPrompt/схему (а за сессию это делали 3 раза), уже спарсенные
продукты навсегда остались бы со старой логикой, пока сайт банка сам не
поменяется (иначе хэш совпадёт и кэш скипнет AI, хотя логика экстракции стала другой).
**Бампать `PromptVersion` (`extract/extract.go`) на любое смысловое изменение
systemPrompt/responseSchema/schemaPromptText.**

### Механика

- **Прямой путь** (большинство банков): `parser.go` перед AI-вызовом сравнивает
  `sha256(PromptVersion+markdown)` с `bank_source_urls.last_markdown_hash`. Совпало
  → `trySkipUnchanged()` (`cache.go`) проверяет `DistinctProductSourceURLs` — если
  это ровно один URL = `task.URL` — просто `TouchProductsBySourceURL` (bump
  `parsed_at`), AI не вызывается.
- **Array-split** (Арванд/ICB/SSB): у каждого элемента массива свой `source_url =
  task.URL + "#" + naturalID` (slug/id элемента, см. `naturalElementID()` в
  `arraysplit.go`) — иначе все элементы одной задачи делили бы один `source_url`
  и были бы неразличимы для кэша. Хэш проверяется `ProductContentHash()` по
  каждому элементу отдельно ДО AI-вызова.
- **Хэш пишем только при `!anyRejected`** (ни одна карточка не забракована
  валидацией в этом прогоне) — иначе забракованная карточка навсегда выпала бы
  из кэша (нет строки в products → вечно считается новой). Честно пустой прогон
  (AI просто ничего не нашёл, не брак) — хэш пишем, это ок.
- **Index-режим (2b) отменился сам** — см. ниже, `cmd/parser` больше не умеет в
  index-режим вообще, значит и кэшировать там нечего.

### Живая проверка (реальные прогоны, не только юнит-тесты)

- Эсхата `/loans/car/` (прямой путь): прогон 1 — 37 сек, реальный AI-ответ.
  Прогон 2 (без изменений на сайте) — 919мс, `ai_raw_response` пуст, `parsed_at`
  обновился. **Подтверждено.**
- Арванд credits (array-split, 7 элементов): прогон 1 — 6 успешно + 1 упал EOF
  (как и раньше, не сломало остальное). Прогон 2 — **6 из 7 пропущены** (0
  AI-вызовов), 7-й (ранее падавший) реально спарсился и получил хэш.
  **Подтверждено.**

### Критика решения (обсуждали, стоит помнить при продолжении)

- Крон стоит `0 3 */10 * *` (раз в 10 дней) — **специально уменьшен**, потому что
  парсер жрал токены впустую на кривых прогонах. После сегодняшних фиксов частоту
  можно/нужно поднять обратно — тогда кэш станет полезнее (больше прогонов =
  больше шансов скипнуть).
- Проверил HTTP conditional GET (`ETag`/`Last-Modified`) как альтернативу
  собственному хэшу — **не подходит**: все проверенные банки шлют
  `Cache-Control: no-store, no-cache, must-revalidate`, ни `ETag`, ни
  `Last-Modified` нет вообще.
- Ссылки в тексте (PDF-пути с CMS-хэшами в URL и т.п.) — потенциальный источник
  ложных "изменений" (хэш меняется, хотя по сути ничего не поменялось). Не фикшено
  отдельно — не критично, просто лишний AI-вызов, не порча данных.

---

## Часть 3 — Архитектура discover/parse (важный разговор под конец)

Пользователь: два независимых пайплайна — **discover** ищет ТОЛЬКО ссылки на
страницы продуктов (по явным `bank_parse_instructions.notes`/`menu_sections`),
**parse** читает ВСЕ `bank_source_urls` и извлекает текст/условия, без
собственного поиска ссылок.

**Было не так:** `cmd/parser` имел свой встроенный "auto-fallback" — если AI при
парсинге детекчил `product_links` (страница вдруг каталог) — сам рекурсивно
обходил ссылки (`gatherFromLinks`/`resolveDetailLinks`, до 40 страниц). Дублировало
работу discover.

**Убрано полностью** (по явному решению пользователя): `gatherFromLinks`,
`resolveDetailLinks`, `detailJob`, `maxDetailPages`, `stripLinkSyntax`, `sameSite`,
`regDomain`, `normalizeURL` — все удалены из `parser.go`. Теперь если AI всё же
вернёт `product_links` — просто `p.log.Warn(...)`, без обхода. Сигнал "иди
чини discovery-инструкцию", не тихая компенсация.

`cmd/discover` НЕ трогали — там логика (если ссылок нет, но продукты нашлись
прямо на стартовой странице — регистрировать саму страницу) — это ЕГО легитимная
работа, не дублирование.

### Аудит `bank_parse_instructions` (kind='product_discovery') — 4 находки

1. **amonatbank/deposit (id=14)** — note: *"если каталог 500 — продукты в меню
   главной"*. Код не может это выполнить: `Scrape()` ошибка → `discover.process`
   делает `return` до вызова AI ([discover.go:103-106](parser/internal/discover/discover.go:103)).
   Инструкция для AI, который её не увидит. **НЕ ПОЧИНЕНО.**
2. **oriyonbank credit+deposit (id=16,17)** — сайт за Cloudflare JS-challenge
   (403 "Attention Required" через Direct стабильно). **ПОЧИНЕНО** (dev):
   `scraper='browser'` на инструкциях И на `bank_source_urls`.
3. **dbt credit+deposit (id=26,27)** — React SPA, прямой GET = пустой
   `<div id="app">`. **ПОЧИНЕНО** (dev): `scraper='browser'` аналогично.
4. **alif** — discovery-инструкция есть только под `deposit`. `installment`-источник
   (`/ru/auto/cars`) в `bank_source_urls` — сирота без своей инструкции, вне
   пайплайна discover→parse. **НЕ ПОЧИНЕНО.**

---

## Что дальше (TODO)

1. Прогнать миграцию `2026_08_16_000001_...` на проде (`php artisan migrate`).
2. Продублировать на проде `UPDATE` по находкам 2/3 (oriyonbank/dbt scraper=browser)
   на `bank_source_urls` И `bank_parse_instructions`.
3. Решить и починить находку 1 (amonatbank note) — либо убрать нерабочую фразу
   про 500, либо завести вторую инструкцию на реальный fallback-URL.
4. Решить находку 4 (alif installment) — написать инструкцию или осознанно
   оставить как ручной source вне discover.
5. Закоммитить всё из "не закоммичено" выше одним(и) коммитом(ами).
6. После деплоя — понаблюдать реальный hit-rate кэша хотя бы 1-2 настоящих
   10-дневных цикла, прежде чем решать, поднимать ли частоту крона.
7. Не проверяли отдельно: `ExtractRates`/курсовой пайплайн (`cmd/rates`, свой
   крон раз в 2 часа) — весь сегодняшний разбор был только про
   credit/deposit-парсинг продуктов, курсы не трогали.
