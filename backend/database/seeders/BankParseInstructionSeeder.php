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
     * @var list<array{bank:string,kind:string,category:?string,start_url:string,menu_sections:?array<int,string>,notes:?string}>
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
            'notes' => 'Курсы на главной в блоке с классом «currency-rate». Табы: «НБТ» (игнорировать) и наличка (buy/sell — Харид/Фурӯш). Бери только вкладку налички: cash, buy/sell по каждой валюте. transfer-курсов нет.',
        ],

        // --- Банк Арванд (arvand.tj) ---
        [
            'bank' => 'Арванд',
            'kind' => 'product_discovery',
            'category' => 'credit',
            'start_url' => 'https://arvand.tj/',
            'menu_sections' => ['Кредиты'],
            'notes' => 'Ссылки на продукты в ШАПКЕ → подменю «Кредиты» (список кредитных продуктов). Собери ссылки на КОНКРЕТНЫЕ продукты. Ссылку-агрегатор «Все кредиты» НЕ добавляй как продукт (это каталог). Только физлица.',
        ],
        [
            'bank' => 'Арванд',
            'kind' => 'product_discovery',
            'category' => 'deposit',
            'start_url' => 'https://arvand.tj/',
            'menu_sections' => ['Вклады'],
            'notes' => 'Ссылки в шапке → подменю «Вклады» (список вкладов). Конкретные продукты, агрегатор «Все вклады» не добавлять. Только физлица.',
        ],
        [
            'bank' => 'Арванд',
            'kind' => 'rates',
            'category' => null,
            'start_url' => 'https://arvand.tj/',
            'menu_sections' => null,
            'notes' => 'Курсы на главной в блоке с классом «arvand-rate». Раздели на cash (наличные) и transfer (переводы), если есть; buy/sell. Игнор юрлиц, золота, курса НБТ.',
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
            'bank' => 'Алиф',
            'kind' => 'rates',
            'category' => null,
            'start_url' => 'https://alif.tj/ru/',
            'menu_sections' => null,
            'notes' => 'Курсы валют на странице — НЕ по классу (классы не уникальны). Найди по СОДЕРЖИМОМУ: таблица/блок с валютами (USD, EUR, RUB) и парами курсов покупка/продажа. cash, buy/sell. Игнор курса НБТ.',
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
            'bank' => 'Амонатбанк', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://amonatbonk.tj/ru/', 'menu_sections' => null,
            'notes' => 'Курсы на главной, вкладки: Физическое лицо / Юридическое лицо / Денежные переводы. cash = Физическое лицо, transfer = Денежные переводы. Игнор юрлиц и Курса НБТ.',
        ],

        // --- Ориёнбонк (oriyonbonk.tj) — каталог на одной странице, без URL продуктов ---
        [
            'bank' => 'Ориёнбонк', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://oriyonbonk.tj/individuals/loans', 'menu_sections' => null,
            'notes' => 'Каталог на ОДНОЙ странице, продукты раскрываются inline, отдельных URL у продуктов НЕТ — извлеки все продукты прямо со страницы (не ищи ссылки).',
        ],
        [
            'bank' => 'Ориёнбонк', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://oriyonbonk.tj/individuals/deposits', 'menu_sections' => null,
            'notes' => 'Каталог на одной странице, продукты inline, отдельных URL нет — извлеки продукты прямо со страницы.',
        ],
        [
            'bank' => 'Ориёнбонк', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://oriyonbonk.tj/', 'menu_sections' => null,
            'notes' => 'Курсы в JSON внутри <script> (Next.js): ключ exchangeRates. cashDesks → cash, transfers → transfer; purchase → buy, sale → sell. Бери USD/EUR/RUB. Игнор nbt/cards/nonCash.',
        ],

        // --- Имон Интернешнл (imon.tj) — Cloudflare, только через r.jina ---
        [
            'bank' => 'Имон', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://imon.tj/loans', 'menu_sections' => null,
            'notes' => 'Каталог, карточки → /loans/<slug> (slug = транслит тадж. названия). Продукт «Насия» (/loans/nasiya) — это рассрочка, относить к installment, не credit.',
        ],
        [
            'bank' => 'Имон', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://imon.tj/deposits', 'menu_sections' => null,
            'notes' => 'Каталог вкладов → /deposits/<slug>.',
        ],
        // installment: /loans/nasiya — прямой источник в BankSourceUrlSeeder.
        [
            'bank' => 'Имон', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://imon.tj/api/exchange-rates?populate=*', 'menu_sections' => null,
            'notes' => 'JSON-эндпоинт Strapi. Массив объектов: ccy=валюта, buyrate=buy, sellrate=sell, rateType. Все строки → category=cash (один тип GISE). Бери USD/EUR/RUB.',
        ],

        // --- Тавхидбанк (tawhidbank.tj) — исламский: нет classic-кредита ---
        [
            'bank' => 'Тавхидбанк', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://www.tawhidbank.tj/personal/deposit', 'menu_sections' => null,
            'notes' => 'Исламские вклады Мудараба/Вакала → /personal/deposit/<slug>. Server-rendered.',
        ],
        [
            'bank' => 'Тавхидбанк', 'kind' => 'product_discovery', 'category' => 'installment',
            'start_url' => 'https://www.tawhidbank.tj/personal/financing', 'menu_sections' => null,
            'notes' => 'Мурабаха-финансирование (рассрочка с наценкой) → /personal/financing/<slug>. Авто-финансирование отдельно: /personal/auto-financing.',
        ],
        [
            'bank' => 'Тавхидбанк', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://www.tawhidbank.tj/personal', 'menu_sections' => null,
            'notes' => 'Таблица курсов (покупка/продажа RUB/USD/EUR) server-rendered. cash. Игнор цен на золото.',
        ],

        // --- ICB (icb.tj) — SPA, через r.jina; детали /RU/<cat>/<id> ---
        [
            'bank' => 'Инвестиционно', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://www.icb.tj/RU/Credit?type=1', 'menu_sections' => null,
            'notes' => 'type=1 = физлица. Карточки → /RU/credit/<id> (числовой id). Исключить бизнес.',
        ],
        [
            'bank' => 'Инвестиционно', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://www.icb.tj/RU/Deposit?type=1', 'menu_sections' => null,
            'notes' => 'Карточки → /RU/deposit/<id>.',
        ],
        [
            'bank' => 'Инвестиционно', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://www.icb.tj/', 'menu_sections' => null,
            'notes' => 'Курсы на главной, разбивка по операциям: Касса, Денежные переводы, По карте и т.д. cash = Касса, transfer = Денежные переводы. Игнор золота и Курса НБТ.',
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
            'bank' => 'Банк развития', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://dbt.tj/ru', 'menu_sections' => null,
            'notes' => 'Курсы на главной (USD/RUB/EUR покупка/продажа). cash.',
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
            'notes' => 'Каталог; карточки → /credits/<slug>. Мало розничных продуктов.',
        ],
        [
            'bank' => 'Международный', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://www.ibt.tj/deposits/', 'menu_sections' => null,
            'notes' => 'Каталог вкладов → /deposits/<slug>.',
        ],
        [
            'bank' => 'Международный', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://www.ibt.tj/', 'menu_sections' => null,
            'notes' => 'Курсы на главной: группы «НБТ» (ИГНОР) и «МБТ Наличные» (buy/sell). cash из «МБТ Наличные».',
        ],

        // --- Коммерцбанк Таджикистана (cbt.tj) — JS, через r.jina ---
        [
            'bank' => 'Коммерц', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://cbt.tj/retail/credits/', 'menu_sections' => null,
            'notes' => 'Каталог физлиц; карточки → /retail/credits/<slug>. Бизнес /entity/ исключить.',
        ],
        [
            'bank' => 'Коммерц', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://cbt.tj/retail/deposit/', 'menu_sections' => null,
            'notes' => 'Каталог → /retail/deposit/<slug> (deposit в ед. числе).',
        ],
        [
            'bank' => 'Коммерц', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://cbt.tj/', 'menu_sections' => null,
            'notes' => 'Блок «Қурби асъор» на главной: харид (buy) / фуруш (sell) по USD/EUR/RUB. cash.',
        ],

        // --- Саноатсодиротбонк (ssb.tj) — SPA, через r.jina; детали /<cat>/<id> ---
        [
            'bank' => 'Саноатсодиротбонк', 'kind' => 'product_discovery', 'category' => 'credit',
            'start_url' => 'https://www.ssb.tj/Credit?type=1', 'menu_sections' => null,
            'notes' => 'type=1 = физлица. Детали /Credit/<id> (числовой). SPA — контент через рендер.',
        ],
        [
            'bank' => 'Саноатсодиротбонк', 'kind' => 'product_discovery', 'category' => 'deposit',
            'start_url' => 'https://www.ssb.tj/Deposit?type=1', 'menu_sections' => null,
            'notes' => 'Детали /Deposit/<id>.',
        ],
        [
            'bank' => 'Саноатсодиротбонк', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://webapi.ssb.tj/SSBRealPersoncurrency', 'menu_sections' => null,
            'notes' => 'JSON физлиц (касса). Плоские ключи: USD_buy/USD_sell/EUR_buy/EUR_sell/RUB_buy/RUB_sell. buy=наш buy, sell=наш sell (без свопа). category=cash. Эндпоинт /currency (курс НБТ) НЕ использовать.',
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
            'notes' => 'JSON касса (RATE_OP). Формат {status,data:{CUR:{buy,sell}}}. ВАЖНО: поля API инвертированы относительно нашей схемы. Наш buy = банк ПОКУПАЕТ (меньшее значение) = поле API "sell". Наш sell = банк ПРОДАЁТ (большее) = поле API "buy". Т.е. наш buy = api.sell, наш sell = api.buy. category=cash. Бери USD/EUR/RUB. (Игнор: CURRENCY=курс НБТ, WAY_COURSE=картой.)',
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
            'bank' => 'Васл', 'kind' => 'rates', 'category' => null,
            'start_url' => 'https://www.vasl.tj/ru/', 'menu_sections' => null,
            'notes' => 'Статический блок курсов на главной: USD/EUR/RUB покупка/продажа. cash. Кредитов и вкладов у банка нет (платёжный/карточный).',
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
