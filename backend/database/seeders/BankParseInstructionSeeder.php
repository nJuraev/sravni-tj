<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Курируемые правила парсинга по каждому банку (источник истины).
 *
 * Каждое правило → строка bank_parse_instructions. Заполняется вручную по мере
 * изучения сайтов банков. Идемпотентно: updateOrInsert по (bank_id, kind, category),
 * поэтому повторный db:seed обновляет существующие строки, не плодит дубли.
 *
 * Стратегии (kind=product_discovery):
 *   A. Из шапки/главной — start_url = главная, menu_sections = разделы меню.
 *   B. Из каталога      — start_url = страница-список продуктов.
 * kind=rates — страница курсов банка (start_url), category = null.
 *
 * Привязка к банку по фрагменту name_ru (устойчиво к пересоздании БД).
 */
class BankParseInstructionSeeder extends Seeder
{
    /**
     * rate_rule (только kind=rates) — конфиг детерминированного (без AI)
     * извлечения курсов из ПЛОСКОГО JSON-эндпоинта. Отсутствует/null — курсы
     * идут старым путём (scrape → AI), см. model.RateRule в парсере.
     *
     * scraper — 'browser' (свой headless Chrome, parser/internal/scrape/browser.go)
     * или 'firecrawl' (платный фолбэк), если источник требует полноценный
     * JS-рендер (client-rendered SPA) либо стоит за anti-bot защитой, которую
     * свой скрейпер (прямой HTTP GET, parser/internal/scrape/direct.go) не
     * проходит. Отсутствует/null (по умолчанию) — Direct, бесплатно.
     *
     * @var list<array{bank:string,kind:string,category:?string,start_url:string,menu_sections:?array<int,string>,notes:?string,rate_rule?:array{format:string,items:list<array{currency:string,category:string,buy_path:string,sell_path:string}>},scraper?:string}>
     */
    private array $rules = [
        // --- Банк Эсхата (стратегия A: ссылки в шапке главной) ---
        [
            'bank' => 'Эсхата',
            'kind' => 'product_discovery',
            'category' => 'credit',
            'start_url' => 'https://eskhata.com/',
            'menu_sections' => ['Кредиты'],
            'notes' => 'Ссылки на страницы продуктов ищи в ШАПКЕ сайта / главном меню. Только физлица.',
        ],
        [
            'bank' => 'Эсхата',
            'kind' => 'product_discovery',
            'category' => 'deposit',
            'start_url' => 'https://eskhata.com/',
            'menu_sections' => ['Вклады', 'Депозиты'],
            'notes' => 'Ссылки в шапке/меню. Только физлица — НЕ юрлица.',
        ],
        [
            'bank' => 'Эсхата',
            'kind' => 'rates',
            'category' => null,
            'start_url' => 'https://eskhata.com/',
            'menu_sections' => null,
            'notes' => 'cash — вкладка «Частным лицам» → «Покупка и продажа». transfer — «Денежные переводы». Игнор: юрлица, золото, погашение кредита, Курс НБТ.',
        ],

        // --- Душанбе Сити Банк (dc.tj) ---
        [
            'bank' => 'Душанбе Сити',
            'kind' => 'product_discovery',
            'category' => 'credit',
            'start_url' => 'https://dc.tj/',
            'menu_sections' => ['Кредиты', 'Кредитные продукты'],
            'notes' => 'Ссылки на продукты в ШАПКЕ → подменю «Кредитные продукты». Только физлица.',
        ],
        [
            // Проверено вживую: deposit.dc.tj — НЕ один продукт (старое
            // предположение было неверным) и НЕ каталог со ссылками — это
            // одна страница, где ЦЕЛИКОМ показаны СРАЗУ 4-5 разных вкладов
            // («Дурахшон», «Шарики боэътимод», депозит для легализации
            // денежных средств, «Пурсамар» + безымянный базовый блок), у
            // каждого своя валюта/срок/ставка/мин.сумма. kind=static_source:
            // URL уже точно известен, discovery (поиск ссылок) тут не нужен
            // вообще — см. BankParseInstructionSeeder::run() и
            // discover.go registerStaticSources.
            'bank' => 'Душанбе Сити',
            'kind' => 'static_source',
            'category' => 'deposit',
            'start_url' => 'https://deposit.dc.tj/',
            'menu_sections' => null,
            'notes' => 'На этой странице НЕСКОЛЬКО РАЗНЫХ депозитных продуктов (не тарифная сетка одного продукта) — у каждого своё название («Дурахшон», «Шарики боэътимод», депозит для легализации денежных средств, «Пурсамар» и др.), своя валюта/срок/ставка/минимальная сумма. Извлеки каждый как ОТДЕЛЬНЫЙ объект в products[].',
        ],
        [
            // Раньше AI-путь по блоку «kurspublish container» на главной.
            // Оказалось: cash («В кассе») в статике ГЛАВНОЙ не рендерится
            // вообще — грузится клиентским fetch по клику на таб (raw HTML
            // содержит только дефолтный таб «Переводы», см. inline <script>
            // на странице). Реальный источник обеих категорий — общий
            // плоский JSON-эндпоинт, различается только query-параметром
            // type. Проверено curl:
            //   ?type=transfer → {"rates":[{"code":"USD","buy":"9.1800","sell":"9.2700"},...]}
            //   ?type=cash     → buy/sell чуть другие (курс кассы ≠ курс перевода)
            // chk_bpi_category требует category IS NULL для kind=rates (одна
            // строка instruction на банк) — cash/transfer не развести по
            // строкам, поэтому per-item 'url' (см. model.RateRuleItem):
            // items без url читают in.StartURL (transfer), items с url —
            // свой (cash). rate_rule — источник истины.
            'bank' => 'Душанбе Сити',
            'kind' => 'rates',
            'category' => null,
            'start_url' => 'https://dc.tj/kurs_nbt_tab.php?type=transfer',
            'menu_sections' => null,
            'notes' => 'JSON: {"rates":[{"code","name","buy","sell"},...]}. Фильтр по code. transfer = таб «Переводы» (start_url), cash = таб «В кассе» (rate_rule.items[].url = ?type=cash).',
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'transfer', 'buy_path' => 'rates[code=USD].buy', 'sell_path' => 'rates[code=USD].sell'],
                    ['currency' => 'EUR', 'category' => 'transfer', 'buy_path' => 'rates[code=EUR].buy', 'sell_path' => 'rates[code=EUR].sell'],
                    ['currency' => 'RUB', 'category' => 'transfer', 'buy_path' => 'rates[code=RUB].buy', 'sell_path' => 'rates[code=RUB].sell'],
                    ['currency' => 'CNY', 'category' => 'transfer', 'buy_path' => 'rates[code=CNY].buy', 'sell_path' => 'rates[code=CNY].sell'],
                    ['currency' => 'USD', 'category' => 'cash', 'url' => 'https://dc.tj/kurs_nbt_tab.php?type=cash', 'buy_path' => 'rates[code=USD].buy', 'sell_path' => 'rates[code=USD].sell'],
                    ['currency' => 'EUR', 'category' => 'cash', 'url' => 'https://dc.tj/kurs_nbt_tab.php?type=cash', 'buy_path' => 'rates[code=EUR].buy', 'sell_path' => 'rates[code=EUR].sell'],
                    ['currency' => 'RUB', 'category' => 'cash', 'url' => 'https://dc.tj/kurs_nbt_tab.php?type=cash', 'buy_path' => 'rates[code=RUB].buy', 'sell_path' => 'rates[code=RUB].sell'],
                    ['currency' => 'CNY', 'category' => 'cash', 'url' => 'https://dc.tj/kurs_nbt_tab.php?type=cash', 'buy_path' => 'rates[code=CNY].buy', 'sell_path' => 'rates[code=CNY].sell'],
                ],
            ],
        ],

        // --- Спитамен Банк (spitamenbank.tj) ---
        [
            // Стратегия B: каталог-список продуктов. Каждый продукт — карточка
            // со ссылкой на детальную страницу /ru/personal/products/credits/<slug>.
            'bank' => 'Спитамен',
            'kind' => 'product_discovery',
            'category' => 'credit',
            'start_url' => 'https://spitamenbank.tj/ru/personal/products/credits/',
            'menu_sections' => null,
            'notes' => 'Это страница-КАТАЛОГ кредитов. Каждый продукт — карточка со ссылкой на детальную страницу вида /ru/personal/products/credits/<slug>. Собери ссылки всех карточек и парси детальные страницы. Только физлица (раздел «Частным лицам»).',
        ],
        [
            'bank' => 'Спитамен',
            'kind' => 'product_discovery',
            'category' => 'deposit',
            'start_url' => 'https://spitamenbank.tj/ru/personal/products/deposits/',
            'menu_sections' => null,
            'notes' => 'Страница-КАТАЛОГ вкладов. Карточки со ссылками на детальные страницы вида /ru/personal/products/deposits/<slug>. Собери ссылки и парси детальные страницы. Только физлица.',
        ],
        [
            'bank' => 'Спитамен',
            'kind' => 'rates',
            'category' => null,
            'start_url' => 'https://spitamenbank.tj/',
            'menu_sections' => null,
            'notes' => 'Курсы на главной в блоке #currency-list (класс «currency-rate»). Наличка — элементы li с атрибутом c_index=1 (НЕ НБТ, тот другой c_index). Бери только c_index=1: cash, buy/sell по каждой валюте. transfer-курсов нет.',
        ],

        // --- Банк Арванд (arvand.tj) ---
        // credit/deposit БЕЗ product_discovery: нашли JSON API, отдающий
        // ВСЕ языки в ОДНОМ ответе на КАЖДОМ поле (title/title_ru/title_tg/
        // title_en, description_*, slogan_*, req_borrowers_*, req_documents_*,
        // review_period_*, loan_amount_*, loan_term_*, interest_rate_*) —
        // нулевой риск рассинхрона языков (в отличие от HTML /person/ vs
        // /tg/person/, тоже рабочих — /person/credits/ = ru БЕЗ префикса,
        // /tg/person/credits/ = tj, но API проще и надёжнее). Заданы напрямую
        // в BankSourceUrlSeeder (arvand.tj/person/api/credits|deposits/).
        [
            // Курсы — плоский МАССИВ тэгированных записей (не объект), нужен
            // json_path с фильтром по двум полям. Проверено curl:
            // [{"type_currency":"CASH_RATE","currency_name":"USD","buy_rate":"9.17","sell_rate":"9.27",...},...]
            // Типы: NBT_RATE (игнор), LOAN_RATE (игнор, для кредитов), TRANSFER_RATE, CASH_RATE.
            'bank' => 'Арванд', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://arvand.tj/api/currencies/', 'menu_sections' => null,
            'notes' => 'JSON: плоский массив записей {type_currency,currency_name,buy_rate,sell_rate}. CASH_RATE→cash, TRANSFER_RATE→transfer. Игнор NBT_RATE и LOAN_RATE. rate_rule ниже — источник истины.',
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'cash', 'buy_path' => '[type_currency=CASH_RATE,currency_name=USD].buy_rate', 'sell_path' => '[type_currency=CASH_RATE,currency_name=USD].sell_rate'],
                    ['currency' => 'EUR', 'category' => 'cash', 'buy_path' => '[type_currency=CASH_RATE,currency_name=EUR].buy_rate', 'sell_path' => '[type_currency=CASH_RATE,currency_name=EUR].sell_rate'],
                    ['currency' => 'RUB', 'category' => 'cash', 'buy_path' => '[type_currency=CASH_RATE,currency_name=RUB].buy_rate', 'sell_path' => '[type_currency=CASH_RATE,currency_name=RUB].sell_rate'],
                    ['currency' => 'USD', 'category' => 'transfer', 'buy_path' => '[type_currency=TRANSFER_RATE,currency_name=USD].buy_rate', 'sell_path' => '[type_currency=TRANSFER_RATE,currency_name=USD].sell_rate'],
                    ['currency' => 'EUR', 'category' => 'transfer', 'buy_path' => '[type_currency=TRANSFER_RATE,currency_name=EUR].buy_rate', 'sell_path' => '[type_currency=TRANSFER_RATE,currency_name=EUR].sell_rate'],
                    ['currency' => 'RUB', 'category' => 'transfer', 'buy_path' => '[type_currency=TRANSFER_RATE,currency_name=RUB].buy_rate', 'sell_path' => '[type_currency=TRANSFER_RATE,currency_name=RUB].sell_rate'],
                ],
            ],
        ],

        // --- Алиф Банк (alif.tj) — кредитов нет, только installment + deposit ---
        // installment-продукты (Авто, Лизинг) — прямые URL в BankSourceUrlSeeder.
        [
            'bank' => 'Алиф',
            'kind' => 'product_discovery',
            'category' => 'deposit',
            'start_url' => 'https://alif.tj/ru/deposit',
            'menu_sections' => null,
            'notes' => 'Страница-КАТАЛОГ депозитов. Карточки со ссылками на детальные страницы вкладов. Собери ссылки и парси детальные страницы. Только физлица.',
        ],
        [
            // Раньше AI-путь с JS-рендером (Next.js SPA, курсы в разметке
            // пустые, дозагружаются клиентским JS) — платный Firecrawl на
            // каждый прогон. Найден прямой JSON API, отдаёт всё одним GET,
            // без рендера. {"localRates":[{...},...],"crossRates":[...]}.
            // Проверено curl: localRates — 18 валют, фильтр по "name".
            // buyValue/sellValue = касса (cash). moneyTransferBuyValue/
            // moneyTransferTradeValue = перевод (transfer) — sell-поле
            // называется "TradeValue", не "SellValue" (несогласованность
            // самого API). nonCashBuyValue/nonCashSellValue (по карте) и
            // visaBuy/walletBuy — игнор (нет такой category в нашей схеме).
            // nbtValue — курс НБТ, игнор. crossRates не используем.
            'bank' => 'Алиф',
            'kind' => 'rates',
            'category' => null,
            'start_url' => 'https://alif.tj/api/rates',
            'menu_sections' => null,
            'notes' => 'JSON: {"localRates":[{"name","buyValue","sellValue","moneyTransferBuyValue","moneyTransferTradeValue",...}]}. Фильтр по name. cash=buyValue/sellValue, transfer=moneyTransferBuyValue/moneyTransferTradeValue. rate_rule — источник истины.',
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'cash', 'buy_path' => 'localRates[name=USD].buyValue', 'sell_path' => 'localRates[name=USD].sellValue'],
                    ['currency' => 'EUR', 'category' => 'cash', 'buy_path' => 'localRates[name=EUR].buyValue', 'sell_path' => 'localRates[name=EUR].sellValue'],
                    ['currency' => 'RUB', 'category' => 'cash', 'buy_path' => 'localRates[name=RUB].buyValue', 'sell_path' => 'localRates[name=RUB].sellValue'],
                    ['currency' => 'CNY', 'category' => 'cash', 'buy_path' => 'localRates[name=CNY].buyValue', 'sell_path' => 'localRates[name=CNY].sellValue'],
                    ['currency' => 'USD', 'category' => 'transfer', 'buy_path' => 'localRates[name=USD].moneyTransferBuyValue', 'sell_path' => 'localRates[name=USD].moneyTransferTradeValue'],
                    ['currency' => 'EUR', 'category' => 'transfer', 'buy_path' => 'localRates[name=EUR].moneyTransferBuyValue', 'sell_path' => 'localRates[name=EUR].moneyTransferTradeValue'],
                    ['currency' => 'RUB', 'category' => 'transfer', 'buy_path' => 'localRates[name=RUB].moneyTransferBuyValue', 'sell_path' => 'localRates[name=RUB].moneyTransferTradeValue'],
                    ['currency' => 'CNY', 'category' => 'transfer', 'buy_path' => 'localRates[name=CNY].moneyTransferBuyValue', 'sell_path' => 'localRates[name=CNY].moneyTransferTradeValue'],
                ],
            ],
        ],

        // --- Амонатбанк (amonatbonk.tj) ---
        // Каталоги /ru/personal/loans/ и /ru/personal/deposits/ — не источник
        // ссылок: loans/ рисует те же карточки, что и в шапке, но кнопки
        // «Подробнее»/«Оформить» без href (JS без перехода, не <a>) — discovery
        // с этой страницы не находит ссылок на детальные страницы вообще.
        // deposits/ отдаёт 500 (Bitrix, undefined constant DEPOSITS). Реальный
        // источник ссылок в обоих случаях — dropdown в ШАПКЕ главной (вкладка
        // «Частным лицам» → пилюли «Кредиты»/«Вклады»), проверено вживую.
        // scraper='browser': на проде в логах видно Cloudflare-защиту перед
        // amonatbonk.tj — свой прямой HTTP-скрейпер (Direct) её не проходит,
        // нужен полноценный JS-рендер headless Chrome. Значение наследуется
        // discovery → discovered bank_source_urls (см. discover.go
        // UpsertSourceURL(..., in.Scraper)), отдельно на каждый найденный URL
        // проставлять не нужно.
        [
            'bank' => 'Амонатбанк', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://amonatbonk.tj/ru/', 'menu_sections' => ['Кредиты'],
            'notes' => 'Ссылки на кредиты бери из ШАПКИ главной (вкладка «Частным лицам» → пилюля «Кредиты», dropdown), НЕ с /ru/personal/loans/ (там карточки без ссылок на детальные страницы). В dropdown ~15 ссылок вида /ru/personal/loans/<slug> и /ru/personal/hypothec/ — включи их все, это реальные кредитные продукты для физлиц (ипотека /ru/personal/hypothec/ — тоже кредит; страничная подсказка про её тарифную сетку задана отдельно на самом источнике, см. BankSourceUrlSeeder). ИСКЛЮЧИ ровно 2 ссылки на /tj/personal/loans/ (не /ru/!): meeri-foizi-karz — это сводная таблица ставок по ВСЕМ кредитам банка, не отдельный продукт; qarzi-imtiyeznok — реальный продукт (кредит промышленности), но существует только в тадж. версии (ru отдаёт «Элемент не найден»), статус для розницы физлиц не подтверждён — не источник до отдельной проверки.',
            'scraper' => 'browser',
        ],
        [
            'bank' => 'Амонатбанк', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://amonatbonk.tj/ru/', 'menu_sections' => ['Вклады'],
            'notes' => 'Ссылки на вклады бери из ШАПКИ главной (вкладка «Частным лицам» → пилюля «Вклады», dropdown), НЕ с /ru/personal/deposits/ (страница отдаёт 500). В dropdown 6 ссылок вида /ru/personal/deposits/<slug> — все включить, это реальные вклады физлиц. Ссылку на сам каталог (/ru/personal/deposits/) внутри dropdown игнорировать — не продукт.',
            'scraper' => 'browser',
        ],
        // Амонатбанк: rates-инструкция снята — виджет individuals.*.buy/sell
        // на деле отдаёт курс НБТ (одно число, buy=sell), не собственный
        // коммерческий курс банка. Показывать нечего — сравнение "лучший
        // курс" требует реальных buy/sell, а не рефересного курса НБТ.
        // Строка удаляется из bank_parse_instructions при следующем сиде
        // (см. run() ниже — удаляет всё, что не в $this->rules).

        // --- Ориёнбонк (oriyonbonk.tj) — каталог на одной странице, без URL продуктов ---
        // Cloudflare отдаёт 403/challenge на прямой GET (та же причина, что и
        // у rates ниже) — проверено вживую: со scraper='browser' обе
        // catalog-страницы отдают полный inline-контент (проверено на
        // credit: 7+ продуктов с суммой/сроком/валютой). На отдельный общий
        // start_url (главная) НЕ переводим — обе страницы САМИ ПО СЕБЕ уже
        // полны (см. Products>0 fallback discover.go), объединять смысла нет.
        [
            // Без /ru/ — дефолтная ТАДЖИКСКАЯ версия сайта. Канон должен быть ru
            // (lang_url_rule на банке сам выводит tj отсюда).
            'bank' => 'Ориёнбонк', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://oriyonbonk.tj/ru/individuals/loans', 'menu_sections' => null,
            'notes' => 'Каталог на ОДНОЙ странице, продукты раскрываются inline, отдельных URL у продуктов НЕТ — извлеки все продукты прямо со страницы (не ищи ссылки).',
            'scraper' => 'browser',
        ],
        [
            'bank' => 'Ориёнбонк', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://oriyonbonk.tj/ru/individuals/deposits', 'menu_sections' => null,
            'notes' => 'Каталог на одной странице, продукты inline, отдельных URL нет — извлеки продукты прямо со страницы.',
            'scraper' => 'browser',
        ],
        [
            // Cloudflare отдаёт 403 на прямой GET (challenge-страница) — свой
            // Direct-скрейпер не проходит, нужен headless Chrome.
            'bank' => 'Ориёнбонк', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://oriyonbonk.tj/', 'menu_sections' => null,
            'notes' => 'Курсы в JSON внутри <script> (Next.js): ключ exchangeRates. cashDesks → cash, transfers → transfer; purchase → buy, sale → sell. Бери USD/EUR/RUB. Игнор nbt/cards/nonCash.',
            'scraper' => 'browser',
        ],

        // --- Имон Интернешнл (imon.tj) — Cloudflare, свой скрейпер не проходит ---
        [
            'bank' => 'Имон', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://imon.tj/loans', 'menu_sections' => null,
            'notes' => 'Каталог, карточки → /loans/<slug> (slug = транслит тадж. названия). Продукт «Насия» (/loans/nasiya) — это рассрочка, относить к installment, не credit.',
            'scraper' => 'browser',
        ],
        [
            'bank' => 'Имон', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://imon.tj/deposits', 'menu_sections' => null,
            'notes' => 'Каталог вкладов → /deposits/<slug>.',
            'scraper' => 'browser',
        ],
        // installment: /loans/nasiya — прямой источник в BankSourceUrlSeeder.
        [
            'bank' => 'Имон', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://imon.tj/api/exchange-rates?populate=*', 'menu_sections' => null,
            'notes' => 'JSON-эндпоинт Strapi. Массив объектов: ccy=валюта, buyrate=buy, sellrate=sell, rateType. Все строки → category=cash (один тип GISE). Бери USD/EUR/RUB. rate_rule ниже — источник истины.',
            // Плоский массив, несколько rateType на валюту — фильтр по ДВУМ
            // полям одновременно (ccy И rateType=GISE).
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'cash', 'buy_path' => '[ccy=USD,rateType=GISE].buyrate', 'sell_path' => '[ccy=USD,rateType=GISE].sellrate'],
                    ['currency' => 'EUR', 'category' => 'cash', 'buy_path' => '[ccy=EUR,rateType=GISE].buyrate', 'sell_path' => '[ccy=EUR,rateType=GISE].sellrate'],
                    ['currency' => 'RUB', 'category' => 'cash', 'buy_path' => '[ccy=RUB,rateType=GISE].buyrate', 'sell_path' => '[ccy=RUB,rateType=GISE].sellrate'],
                ],
            ],
        ],

        // --- Тавхидбанк (tawhidbank.tj) — исламский: нет classic-кредита ---
        [
            'bank' => 'Тавхидбанк', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://www.tawhidbank.tj/personal/deposit', 'menu_sections' => null,
            'notes' => 'Исламские вклады Мудараба/Вакала → /personal/deposit/<slug>. Client-rendered Angular SPA (не server-rendered!) — нужен JS-рендер.',
            'scraper' => 'browser',
        ],
        [
            // Angular SPA, client-rendered (curl видит только пустой shell) —
            // нужен полноценный JS-рендер (Jina), НЕ "server-rendered", как в
            // старых заметках выше/ниже. Язык переключается ЧИСТО на клиенте
            // (Angular LanguageService/selectedLanguage$, вероятно localStorage)
            // — ни пути, ни query, ни заголовка не нашли. НЕ ПРОВЕРЕНО, форвардит
            // ли Jina наш Accept-Language на ориджин (если да — можно завести
            // lang_url_rule type=header, как у ICB; если нет — язык не задать
            // вообще, увидим дефолт). Нужна живая проверка (devtools/
            // PARSER_DEBUG_LOG) перед тем как заводить правило.
            'bank' => 'Тавхидбанк', 'kind' => 'product_discovery', 'category' => 'installment',
            'start_url' => 'https://www.tawhidbank.tj/personal/financing', 'menu_sections' => null,
            'notes' => 'Мурабаха-финансирование (рассрочка с наценкой) → /personal/financing/<slug>. Авто-финансирование отдельно: /personal/auto-financing. Client-rendered Angular SPA — нужен JS-рендер.',
            'scraper' => 'browser',
        ],
        [
            // Проверено curl: чистый JSON, другой хост/порт (обходит проблемы
            // основного домена, как у ICB). Массив ИМЁННЫХ групп → внутри
            // МАССИВ ПОЗИЦИОННЫХ троек [currency,buy,sell,accounting], без
            // имён полей вообще. {"data":[["Cash_Rate",[["CNY","1.34","1.39",
            // "1.36"],["RUB",...],["USD","9.20","9.29","9.27"],["EUR",...]]],
            // ["MoneyTransfer_Rate",[...]],["NonCash_Rate",[...]]],"bdate":"..."}
            'bank' => 'Тавхидбанк', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://pay.tawhid.tj:4436/twbrates/v2/Handler2.ashx', 'menu_sections' => null,
            'notes' => 'JSON: data = массив [имя_группы, массив троек [currency,buy,sell,accounting]]. Cash_Rate→cash, MoneyTransfer_Rate→transfer. Игнор NonCash_Rate. rate_rule ниже — источник истины.',
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'cash', 'buy_path' => 'data[0=Cash_Rate].1[0=USD].1', 'sell_path' => 'data[0=Cash_Rate].1[0=USD].2'],
                    ['currency' => 'EUR', 'category' => 'cash', 'buy_path' => 'data[0=Cash_Rate].1[0=EUR].1', 'sell_path' => 'data[0=Cash_Rate].1[0=EUR].2'],
                    ['currency' => 'RUB', 'category' => 'cash', 'buy_path' => 'data[0=Cash_Rate].1[0=RUB].1', 'sell_path' => 'data[0=Cash_Rate].1[0=RUB].2'],
                    ['currency' => 'USD', 'category' => 'transfer', 'buy_path' => 'data[0=MoneyTransfer_Rate].1[0=USD].1', 'sell_path' => 'data[0=MoneyTransfer_Rate].1[0=USD].2'],
                    ['currency' => 'EUR', 'category' => 'transfer', 'buy_path' => 'data[0=MoneyTransfer_Rate].1[0=EUR].1', 'sell_path' => 'data[0=MoneyTransfer_Rate].1[0=EUR].2'],
                    ['currency' => 'RUB', 'category' => 'transfer', 'buy_path' => 'data[0=MoneyTransfer_Rate].1[0=RUB].1', 'sell_path' => 'data[0=MoneyTransfer_Rate].1[0=RUB].2'],
                ],
            ],
        ],

        // --- ICB (icb.tj) ---
        // credit/deposit БЕЗ product_discovery: r.jina блокировал ЦЕЛИКОМ
        // www.icb.tj (страница стучится на локальный IP, triggers anti-SSRF).
        // Реальный источник — публичный JSON API на ДРУГОМ хосте/порту
        // (icb.tj:8384), задан напрямую в BankSourceUrlSeeder. Jina/AI-discovery
        // не участвуют вообще — прямой HTTP GET, обходит SSRF-блок целиком.
        [
            // Курсы переведены на отдельный публичный JSON-эндпоинт (другой хост,
            // порт 8384) — НЕ через www.icb.tj, значит SSRF-блок Jina выше на этот
            // путь не действует вообще (сюда даже не идём через Jina/AI).
            'bank' => 'Инвестиционно', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://icb.tj:8384/api/rates', 'menu_sections' => null,
            'notes' => 'Курсы на главной, разбивка по операциям: Касса, Денежные переводы, По карте и т.д. cash = Касса, transfer = Денежные переводы. Игнор золота и Курса НБТ. rate_rule ниже — источник истины, notes оставлены как справка.',
            // Проверено curl: {"data":{"cash":{"usd":{"buy":"9.200000","sell":"9.280000"},...},
            // "remittance":{"usd":{...},...},"nbt_rates":{...},"card":{...},"deposit":{...}}}.
            // cash → category=cash, remittance → category=transfer. nbt_rates/card/deposit — игнор.
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'cash', 'buy_path' => 'data.cash.usd.buy', 'sell_path' => 'data.cash.usd.sell'],
                    ['currency' => 'EUR', 'category' => 'cash', 'buy_path' => 'data.cash.eur.buy', 'sell_path' => 'data.cash.eur.sell'],
                    ['currency' => 'RUB', 'category' => 'cash', 'buy_path' => 'data.cash.rub.buy', 'sell_path' => 'data.cash.rub.sell'],
                    ['currency' => 'USD', 'category' => 'transfer', 'buy_path' => 'data.remittance.usd.buy', 'sell_path' => 'data.remittance.usd.sell'],
                    ['currency' => 'EUR', 'category' => 'transfer', 'buy_path' => 'data.remittance.eur.buy', 'sell_path' => 'data.remittance.eur.sell'],
                    ['currency' => 'RUB', 'category' => 'transfer', 'buy_path' => 'data.remittance.rub.buy', 'sell_path' => 'data.remittance.rub.sell'],
                ],
            ],
        ],

        // --- Банк развития Таджикистана (dbt.tj) — JS, через r.jina ---
        [
            'bank' => 'Банк развития', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://dbt.tj/ru/credits', 'menu_sections' => null,
            'notes' => 'Каталог; детали /ru/credits/<slug> (напр. consumer_credit, mortgage).',
        ],
        [
            'bank' => 'Банк развития', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://dbt.tj/ru/deposits', 'menu_sections' => null,
            'notes' => 'Продукты inline на странице, отдельных URL может не быть — извлеки со страницы.',
        ],
        [
            // Проверено curl: чистый JSON, [{"key":"cash","data":[{"title":"USD",
            // "value_buy":"9.20","value_sale":"9.30"},...]},{"key":"transfers",...},
            // {"key":"non_cash",...},{"key":"nbt",...}]. Массив групп, внутри —
            // вложенный массив по валюте. Поле продажи — "value_sale" (не value_sell!).
            'bank' => 'Банк развития', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://dbt.tj/api/v1/calculators', 'menu_sections' => null,
            'notes' => 'JSON: массив групп (key=cash/transfers/non_cash/nbt), внутри массив по валюте (title/value_buy/value_sale). cash→cash, transfers→transfer. Игнор non_cash и nbt. rate_rule ниже — источник истины.',
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'cash', 'buy_path' => '[key=cash].data[title=USD].value_buy', 'sell_path' => '[key=cash].data[title=USD].value_sale'],
                    ['currency' => 'EUR', 'category' => 'cash', 'buy_path' => '[key=cash].data[title=EUR].value_buy', 'sell_path' => '[key=cash].data[title=EUR].value_sale'],
                    ['currency' => 'RUB', 'category' => 'cash', 'buy_path' => '[key=cash].data[title=RUB].value_buy', 'sell_path' => '[key=cash].data[title=RUB].value_sale'],
                    ['currency' => 'USD', 'category' => 'transfer', 'buy_path' => '[key=transfers].data[title=USD].value_buy', 'sell_path' => '[key=transfers].data[title=USD].value_sale'],
                    ['currency' => 'EUR', 'category' => 'transfer', 'buy_path' => '[key=transfers].data[title=EUR].value_buy', 'sell_path' => '[key=transfers].data[title=EUR].value_sale'],
                    ['currency' => 'RUB', 'category' => 'transfer', 'buy_path' => '[key=transfers].data[title=RUB].value_buy', 'sell_path' => '[key=transfers].data[title=RUB].value_sale'],
                ],
            ],
        ],

        // --- Актив Банк (activbank.tj) — server-rendered ---
        // Проверено вживую: главная (Direct, TLS уже покрыт aia.go — см.
        // scrape/aia.go, отдельного scraper='browser' не нужно) одним
        // запросом отдаёт ссылки И на кредиты, И на вклады — общий start_url
        // вместо двух отдельных catalog-страниц, попадает в объединённый
        // discovery (см. discover.go processGroup): один скрейп+один
        // AI-вызов на банк вместо двух с разным входом.
        [
            'bank' => 'Актив', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://activbank.tj/', 'menu_sections' => ['Кредиты'],
            'notes' => 'Ссылки на кредиты бери из ШАПКИ главной, dropdown «Кредиты» (не с /credits/chastnym-klientam — это тоже рабочий каталог, но незачем скрейпить дважды). Карточки → /credit/<slug>. Бизнес (/credits/biznesu и подобные) исключить.',
        ],
        [
            'bank' => 'Актив', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://activbank.tj/', 'menu_sections' => ['Вклады'],
            'notes' => 'Ссылки на вклады бери из ШАПКИ главной, dropdown «Вклады». Карточки → /deposit/<slug>. Бизнес-вклады исключить.',
        ],
        [
            'bank' => 'Актив', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://activbank.tj/exchange-rates', 'menu_sections' => null,
            'notes' => 'Таблица, вкладки: Частным лицам / Юрлицам / По карточкам / Денежные переводы / НБТ. cash = Частным лицам, transfer = Денежные переводы. Игнор юрлиц и НБТ.',
        ],

        // --- Международный банк Таджикистана (ibt.tj) ---
        [
            'bank' => 'Международный', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://www.ibt.tj/credits/', 'menu_sections' => null,
            'notes' => 'Каталог; карточки → /credits/<slug>. Мало розничных продуктов. ВАЖНО: карточка /credits/novyy-kredit («Кредит "Бизнес"») — бизнес-продукт, затесавшийся в розничный каталог (описание «на развитие бизнеса», сумма до 10 000 000 сомони) — ИСКЛЮЧИТЬ, несмотря на то что лежит среди физлиц.',
        ],
        [
            // Проверено живьём (curl + рендер): депозиты — ОДНА страница, два
            // продукта переключаются JS-табами, БЕЗ отдельных URL (в отличие от
            // credits выше, где детальные страницы реально есть).
            'bank' => 'Международный', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://www.ibt.tj/deposits/', 'menu_sections' => null,
            'notes' => 'НЕ каталог со ссылками — одна страница с двумя вкладами («Ҷамъ»/«Наврас»), переключаемыми табами, отдельных URL у продуктов НЕТ — извлеки оба продукта прямо со страницы (не ищи ссылки).',
        ],
        [
            'bank' => 'Международный', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://www.ibt.tj/', 'menu_sections' => null,
            'notes' => 'Курсы на главной: группы «НБТ» (ИГНОР) и «МБТ Наличные» (buy/sell). cash из «МБТ Наличные».',
        ],

        // --- Коммерцбанк Таджикистана (cbt.tj) — JS-рендер, свой скрейпер не читает ---
        [
            // Раньше product_discovery по /retail/credits/ (каталог со slug-ссылками) —
            // сайт переехал: каталог теперь www.cbt.tj/credits (БЕЗ ссылок на детальные
            // страницы и БЕЗ процентной ставки — только сумма/срок тизером), реальные
            // условия — на www.cbt.tj/credits/<id>, id ЧИСЛОВОЙ (1,2,3,...), не slug.
            // Проверено вживую (headless): id=1..N — разные продукты (тот же порядок,
            // что на каталоге), несуществующий id НЕ отдаёт HTTP 404 (SPA всегда 200,
            // одна и та же пустая обёртка) — discover.go определяет "промах" по
            // содержимому (сравнение с пробником id=0), не по коду ответа. kind
            // sequential_ids перебирает id и сам регистрирует источники в
            // bank_source_urls — обычный AI-discovery тут не подходит (ссылок для
            // AI на странице нет вообще).
            'bank' => 'Коммерц', 'kind' => 'sequential_ids', 'category' => 'credit',
            'start_url' => 'https://www.cbt.tj/credits', 'menu_sections' => null,
            'notes' => null,
            'scraper' => 'browser',
        ],
        [
            // Тот же паттерн, что и credit выше (см. комментарий там): каталог
            // www.cbt.tj/deposits теперь без ссылок/условий, детальные страницы —
            // www.cbt.tj/deposits/<числовой id>. Проверено вживую: id=1-5 —
            // реальные вклады («Дурахшон»/«Фаврӣ»/«V.I.P»/«Орзу»/«АВФ»), id=0/6+ —
            // пустая обёртка (промах).
            'bank' => 'Коммерц', 'kind' => 'sequential_ids', 'category' => 'deposit',
            'start_url' => 'https://www.cbt.tj/deposits', 'menu_sections' => null,
            'notes' => null,
            'scraper' => 'browser',
        ],
        [
            'bank' => 'Коммерц', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://cbt.tj/', 'menu_sections' => null,
            'notes' => 'Блок «Қурби асъор» на главной: харид (buy) / фуруш (sell) по USD/EUR/RUB. cash.',
        ],

        // --- Саноатсодиротбонк (ssb.tj) ---
        // credit/deposit БЕЗ product_discovery: SPA грузит контент по AJAX,
        // discovery на HTML-странице находил пусто/не тот язык. Реальный
        // источник — статичные AJAX JSON-эндпоинты, заданы напрямую в
        // BankSourceUrlSeeder (webapi.ssb.tj/api/Credit|Deposit?language_id=2).
        [
            'bank' => 'Саноатсодиротбонк', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://webapi.ssb.tj/SSBRealPersoncurrency', 'menu_sections' => null,
            'notes' => 'JSON физлиц (касса). Плоские ключи: USD_buy/USD_sell/EUR_buy/EUR_sell/RUB_buy/RUB_sell. buy=наш buy, sell=наш sell (без свопа). category=cash. Эндпоинт /currency (курс НБТ) НЕ использовать.',
            // Проверено curl: {"USD_buy":"9.2","USD_sell":"9.3","EUR_buy":"10.4",
            // "EUR_sell":"10.7","RUB_buy":"0.1207","RUB_sell":"0.1231"} — значения
            // строками, dotPathFloat в парсере это разбирает. Детерминированно,
            // без AI (см. model.RateRule) — notes выше оставлены как справка.
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'cash', 'buy_path' => 'USD_buy', 'sell_path' => 'USD_sell'],
                    ['currency' => 'EUR', 'category' => 'cash', 'buy_path' => 'EUR_buy', 'sell_path' => 'EUR_sell'],
                    ['currency' => 'RUB', 'category' => 'cash', 'buy_path' => 'RUB_buy', 'sell_path' => 'RUB_sell'],
                ],
            ],
        ],

        // --- Фридом банк (freedombank.tj) — кредит на поддомене ---
        [
            'bank' => 'Фридом', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://credit.freedombank.tj/', 'menu_sections' => null,
            'notes' => 'Один продукт «Цифровой кредит» на поддомене-лендинге, каталога нет — извлеки продукт прямо со страницы.',
        ],
        [
            // Direct отдавал пустой список ссылок (JS-рендер) — проверено
            // вживую: со scraper='browser' находятся реальные карточки
            // (/clients/deposits/reliable|child|commutative).
            'bank' => 'Фридом', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://www.freedombank.tj/clients/deposits', 'menu_sections' => null,
            'notes' => 'Каталог; карточки → /clients/deposits/<slug>.',
            'scraper' => 'browser',
        ],
        [
            'bank' => 'Фридом', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://freedombank.tj/api/payment/exchange-rates/RATE_OP', 'menu_sections' => null,
            'notes' => 'JSON касса (RATE_OP). Формат {status,data:{CUR:{buy,sell}}}. ВАЖНО: поля API инвертированы относительно нашей схемы. Наш buy = банк ПОКУПАЕТ (меньшее значение) = поле API "sell". Наш sell = банк ПРОДАЁТ (большее) = поле API "buy". Т.е. наш buy = api.sell, наш sell = api.buy. category=cash. Бери USD/EUR/RUB. (Игнор: CURRENCY=курс НБТ, WAY_COURSE=картой.) rate_rule ниже — источник истины, notes оставлены как справка.',
            // Проверено curl: {"status":"success","data":{"USD":{"buy":9.3,"sell":9.18},...}}
            // buy>sell везде — инверсия подтверждена. buy_path/sell_path СОЗНАТЕЛЬНО
            // перекрёстные (наш buy = их sell).
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'cash', 'buy_path' => 'data.USD.sell', 'sell_path' => 'data.USD.buy'],
                    ['currency' => 'EUR', 'category' => 'cash', 'buy_path' => 'data.EUR.sell', 'sell_path' => 'data.EUR.buy'],
                    ['currency' => 'RUB', 'category' => 'cash', 'buy_path' => 'data.RUB.sell', 'sell_path' => 'data.RUB.buy'],
                ],
            ],
        ],

        // --- Хумо Бонк (humo.tj) ---
        // Раньше считали каталогом с реальными детальными URL (/ru/credit|
        // deposit/<slug>) — проверено ЗАНОВО вживую (браузер, дождались
        // рендера): сайт сменил структуру, карточки продуктов теперь чисто
        // JS (кнопки без href на подстраницы, в интерактивном дереве страницы
        // ТОЛЬКО ссылки шапки/футера) — обычный discovery тут структурно не
        // может сработать. kind=static_source: URL уже точно известен,
        // AI на поиск ссылок не тратим вообще.
        [
            'bank' => 'Хумо', 'kind' => 'static_source', 'category' => 'credit',
            'start_url' => 'https://www.humo.tj/ru/credit', 'menu_sections' => null,
            'notes' => 'Это НЕ каталог со ссылками — кнопки карточек JS-заглушки без реального href на подстраницы. На странице целиком показаны несколько РАЗНЫХ кредитов для физлиц (смешаны с бизнес — их пропускай) — извлеки каждый как отдельный объект в products[].',
        ],
        [
            'bank' => 'Хумо', 'kind' => 'static_source', 'category' => 'deposit',
            'start_url' => 'https://www.humo.tj/ru/deposit', 'menu_sections' => null,
            'notes' => 'Это НЕ каталог со ссылками — кнопки карточек JS-заглушки без реального href на подстраницы. На странице целиком показаны несколько РАЗНЫХ вкладов для физлиц — извлеки каждый как отдельный объект в products[].',
        ],
        [
            // Раньше AI-путь ("server-rendered таблица") — сайт переехал на
            // Next.js, реального рынка "cash" на странице больше НЕТ, только
            // один таб «Переводы» (transfer). Числа лежат внутри HTML как
            // RSC flight-стрим — Next.js сериализует вложенный контент как
            // JSON-СТРОКУ внутри JSON (кавычки экранированы буквально,
            // `\"currency\":[...]` прямо в байтах ответа). html_json это
            // умеет (см. model.RateRule, rates/deterministic.go) — снимает
            // escape сам, без ручной пометки формата. Маркер уникален
            // (проверено — одно совпадение на странице).
            // Проверено curl+браузер: buy/sell — числа (не строки), 3 валюты.
            'bank' => 'Хумо', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://www.humo.tj/ru/', 'menu_sections' => null,
            'notes' => 'JSON внутри HTML (RSC): [{"type":"Переводы","currencyRates":[{"currency","buy","sell"},...]}]. Только transfer, cash больше нет. rate_rule — источник истины.',
            'rate_rule' => [
                'format' => 'html_json',
                'json_marker' => '\"currency\":[',
                'items' => [
                    ['currency' => 'USD', 'category' => 'transfer', 'buy_path' => '[type=Переводы].currencyRates[currency=USD].buy', 'sell_path' => '[type=Переводы].currencyRates[currency=USD].sell'],
                    ['currency' => 'EUR', 'category' => 'transfer', 'buy_path' => '[type=Переводы].currencyRates[currency=EUR].buy', 'sell_path' => '[type=Переводы].currencyRates[currency=EUR].sell'],
                    ['currency' => 'RUB', 'category' => 'transfer', 'buy_path' => '[type=Переводы].currencyRates[currency=RUB].buy', 'sell_path' => '[type=Переводы].currencyRates[currency=RUB].sell'],
                ],
            ],
        ],

        // --- Васл Бонк (vasl.tj) — платёжный банк: кредитов/вкладов НЕТ, только курсы ---
        [
            // Старый start_url (www + /ru/) — 404 живьём, проверено curl.
            // Рабочий домен без www, без языкового сегмента (сайт одноязычный,
            // /ru/ и /tj/ на React-SPA рисуют заглушку "в разработке").
            'bank' => 'Васл', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://vasl.tj/', 'menu_sections' => null,
            'notes' => 'Client-rendered React SPA за Cloudflare (не статический блок — нужен полноценный JS-рендер). USD/EUR/RUB (+ бонусом CNY/UZS) покупка/продажа. cash, без инверсии (buy < sell). Кредитов и вкладов у банка нет (платёжный/карточный).',
            'scraper' => 'browser',
        ],
    ];

    public function run(): void
    {
        $applied = 0;
        // Синхронизация: id каждой применённой строки — чтобы после цикла
        // удалить всё, что было убрано из $this->rules (иначе updateOrInsert
        // сам по себе НИКОГДА не удаляет — правило, убранное из массива,
        // молча остаётся активной строкой в БД навсегда при повторном
        // db:seed на уже засеянной базе).
        $appliedIds = [];

        foreach ($this->rules as $r) {
            $bankId = DB::table('banks')
                ->where('name_ru', 'ILIKE', '%'.$r['bank'].'%')
                ->value('id');

            if ($bankId === null) {
                $this->command?->warn("BankParseInstructionSeeder: банк '{$r['bank']}' не найден, пропуск.");
                continue;
            }

            DB::table('bank_parse_instructions')->updateOrInsert(
                [
                    'bank_id' => $bankId,
                    'kind' => $r['kind'],
                    'category' => $r['category'],
                ],
                [
                    'start_url' => $r['start_url'],
                    'menu_sections' => $r['menu_sections'] !== null ? json_encode($r['menu_sections'], JSON_UNESCAPED_UNICODE) : null,
                    'notes' => $r['notes'],
                    'rate_rule' => isset($r['rate_rule']) ? json_encode($r['rate_rule'], JSON_UNESCAPED_UNICODE) : null,
                    'scraper' => $r['scraper'] ?? null,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $id = DB::table('bank_parse_instructions')
                ->where('bank_id', $bankId)
                ->where('kind', $r['kind'])
                ->when(
                    $r['category'] === null,
                    fn ($q) => $q->whereNull('category'),
                    fn ($q) => $q->where('category', $r['category']),
                )
                ->value('id');
            if ($id !== null) {
                $appliedIds[] = $id;
            }
            $applied++;
        }

        // Защита от случайного полного сноса таблицы: удаляем "осиротевшие"
        // строки, только если цикл выше реально что-то применил.
        $deleted = 0;
        if (! empty($appliedIds)) {
            $deleted = DB::table('bank_parse_instructions')->whereNotIn('id', $appliedIds)->delete();
        }

        $this->command?->info("BankParseInstructionSeeder: применено правил — {$applied}, удалено устаревших строк — {$deleted}.");
    }
}
