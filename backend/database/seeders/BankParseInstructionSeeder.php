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
            // Депозит один, на поддомене — сама страница продукта.
            // Discovery увидит продукт (не ссылки) и зарегистрирует этот URL источником.
            'bank' => 'Душанбе Сити',
            'kind' => 'product_discovery',
            'category' => 'deposit',
            'start_url' => 'https://deposit.dc.tj/',
            'menu_sections' => null,
            'notes' => 'Это страница самого депозитного продукта (поддомен). Извлеки продукт прямо со страницы, искать ссылки не нужно.',
        ],
        [
            'bank' => 'Душанбе Сити',
            'kind' => 'rates',
            'category' => null,
            'start_url' => 'https://dc.tj/',
            'menu_sections' => null,
            'notes' => 'Курсы в блоке с классом «kurspublish container». Раздели на cash (наличные) и transfer (переводы), buy/sell. Игнор юрлиц, золота, курса НБТ.',
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
            // Next.js SPA: значения buy/sell в разметке ПУСТЫЕ (currenciesList
            // содержит только id/label/иконку) — курсы дозагружаются клиентским
            // JS уже после рендера. Direct (статичный GET) их не видит вообще —
            // нужен полноценный JS-рендер.
            'bank' => 'Алиф',
            'kind' => 'rates',
            'category' => null,
            'start_url' => 'https://alif.tj/ru/',
            'menu_sections' => null,
            'notes' => 'Курсы валют на странице — НЕ по классу (классы не уникальны). Найди по СОДЕРЖИМОМУ: таблица/блок с валютами (USD, EUR, RUB) и парами курсов покупка/продажа. cash, buy/sell. Игнор курса НБТ.',
            'scraper' => 'browser',
        ],

        // --- Амонатбанк (amonatbonk.tj) ---
        [
            'bank' => 'Амонатбанк', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://amonatbonk.tj/ru/personal/loans/', 'menu_sections' => null,
            'notes' => 'Каталог кредитов физлиц. Карточки → /ru/personal/loans/<slug>; ипотека отдельно /ru/personal/hypothec/. Исключить бизнес /ru/business/.',
        ],
        [
            'bank' => 'Амонатбанк', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://amonatbonk.tj/ru/personal/deposits/', 'menu_sections' => null,
            'notes' => 'Каталог вкладов физлиц → /ru/personal/deposits/<slug>. Если каталог отдаёт 500 — продукты есть в меню главной.',
        ],
        [
            // Курс на главной — пустые <span id="ambRowUSDBUY"> и т.п.,
            // заполняются JS уже после рендера (ни Direct, ни headless Chrome
            // с ожиданием их не видели). Найден реальный источник — плоский
            // JSON AJAX-эндпоинт того же виджета (Bitrix), без сессии/кэш-
            // бастера, отдаёт все три вкладки сразу. individuals=физлица
            // (cash), remittances=переводы (transfer), legal=юрлица (игнор).
            // Детерминированно, без AI (см. model.RateRule) — notes ниже
            // оставлены как справка про исходную страницу.
            'bank' => 'Амонатбанк', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://amonatbonk.tj/bitrix/templates/amonatbonk/ajax/ambApi.php', 'menu_sections' => null,
            'notes' => 'Курсы на главной, вкладки: Физическое лицо / Юридическое лицо / Денежные переводы. cash = Физическое лицо, transfer = Денежные переводы. Игнор юрлиц и Курса НБТ.',
            'rate_rule' => [
                'format' => 'json_path',
                'items' => [
                    ['currency' => 'USD', 'category' => 'cash', 'buy_path' => 'individuals.USD.buy', 'sell_path' => 'individuals.USD.sell'],
                    ['currency' => 'EUR', 'category' => 'cash', 'buy_path' => 'individuals.EUR.buy', 'sell_path' => 'individuals.EUR.sell'],
                    ['currency' => 'RUB', 'category' => 'cash', 'buy_path' => 'individuals.RUB.buy', 'sell_path' => 'individuals.RUB.sell'],
                    ['currency' => 'CNY', 'category' => 'cash', 'buy_path' => 'individuals.CNY.buy', 'sell_path' => 'individuals.CNY.sell'],
                    ['currency' => 'USD', 'category' => 'transfer', 'buy_path' => 'remittances.USD.buy', 'sell_path' => 'remittances.USD.sell'],
                    ['currency' => 'EUR', 'category' => 'transfer', 'buy_path' => 'remittances.EUR.buy', 'sell_path' => 'remittances.EUR.sell'],
                    ['currency' => 'RUB', 'category' => 'transfer', 'buy_path' => 'remittances.RUB.buy', 'sell_path' => 'remittances.RUB.sell'],
                ],
            ],
        ],

        // --- Ориёнбонк (oriyonbonk.tj) — каталог на одной странице, без URL продуктов ---
        [
            // Без /ru/ — дефолтная ТАДЖИКСКАЯ версия сайта. Канон должен быть ru
            // (lang_url_rule на банке сам выводит tj отсюда).
            'bank' => 'Ориёнбонк', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://oriyonbonk.tj/ru/individuals/loans', 'menu_sections' => null,
            'notes' => 'Каталог на ОДНОЙ странице, продукты раскрываются inline, отдельных URL у продуктов НЕТ — извлеки все продукты прямо со страницы (не ищи ссылки).',
        ],
        [
            'bank' => 'Ориёнбонк', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://oriyonbonk.tj/ru/individuals/deposits', 'menu_sections' => null,
            'notes' => 'Каталог на одной странице, продукты inline, отдельных URL нет — извлеки продукты прямо со страницы.',
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
        [
            'bank' => 'Актив', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://activbank.tj/credits/chastnym-klientam', 'menu_sections' => null,
            'notes' => 'Каталог физлиц; карточки → /credit/<slug>. Бизнес /credits/biznesu исключить.',
        ],
        [
            'bank' => 'Актив', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://activbank.tj/deposits', 'menu_sections' => null,
            'notes' => 'Карточки → /deposit/<slug>. Бизнес-вклады исключить.',
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
            'bank' => 'Коммерц', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://cbt.tj/retail/credits/', 'menu_sections' => null,
            'notes' => 'Каталог физлиц; карточки → /retail/credits/<slug>. Бизнес /entity/ исключить.',
            'scraper' => 'browser',
        ],
        [
            'bank' => 'Коммерц', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://cbt.tj/retail/deposit/', 'menu_sections' => null,
            'notes' => 'Каталог → /retail/deposit/<slug> (deposit в ед. числе).',
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
            'bank' => 'Фридом', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://www.freedombank.tj/clients/deposits', 'menu_sections' => null,
            'notes' => 'Каталог; карточки → /clients/deposits/<slug>.',
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
        [
            'bank' => 'Хумо', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://www.humo.tj/ru/credit', 'menu_sections' => null,
            'notes' => 'Каталог; карточки → /ru/credit/<slug>. Смешаны физ/бизнес — брать только физлиц.',
        ],
        [
            'bank' => 'Хумо', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://www.humo.tj/ru/deposit', 'menu_sections' => null,
            'notes' => 'Каталог → /ru/deposit/<slug>.',
        ],
        [
            'bank' => 'Хумо', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://www.humo.tj/ru/', 'menu_sections' => null,
            'notes' => 'Таблица «Курс валют» на главной (server-rendered), Покупка/Продажа USD/EUR/RUB. cash.',
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
            $applied++;
        }

        $this->command?->info("BankParseInstructionSeeder: применено правил — {$applied}.");
    }
}
