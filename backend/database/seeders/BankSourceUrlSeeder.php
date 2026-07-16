<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Сидер URL-источников парсинга (bank_source_urls).
 *
 * ВАЖНО: URL получены АВТООБНАРУЖЕНИЕМ (анализ сайтов/sitemap/JS-бандлов)
 * и ТРЕБУЮТ РУЧНОЙ СВЕРКИ перед запуском парсера в проде — часть сайтов
 * это SPA, и каталоги подтверждались косвенно (роутер, sitemap, навигация).
 *
 * email доставки лида здесь НАМЕРЕННО оставлен null: адрес назначения
 * заявок по категории (кредит/депозит) задаётся отдельно вручную в БД.
 *
 * bank_id резолвится по banks.slug; банки без записи пропускаются.
 * Сидер идемпотентен: updateOrInsert по уникальной колонке url (uq_bsu_url).
 * Данные встроены прямо в сидер — воспроизводимо без сети.
 */
class BankSourceUrlSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $seen = []; // дедупликация url внутри входных данных

        foreach ($this->sources() as $entry) {
            $slug = $entry['slug'];

            $bankId = DB::table('banks')->where('slug', $slug)->value('id');
            if ($bankId === null) {
                // Банка нет в БД — пропускаем все его источники.
                continue;
            }

            $pairs = [];
            foreach ($entry['credit_urls'] as $item) {
                $pairs[] = ['credit', $item];
            }
            foreach (($entry['installment_urls'] ?? []) as $item) {
                $pairs[] = ['installment', $item];
            }
            foreach ($entry['deposit_urls'] as $item) {
                $pairs[] = ['deposit', $item];
            }

            foreach ($pairs as [$category, $item]) {
                // Элемент — либо просто URL-строка, либо ['url'=>..., 'array_path'=>...,
                // 'scraper'=>...]. array_path — array-split режим (см. parser/internal/
                // parser/arraysplit.go): источник — JSON-массив продуктов целиком,
                // array_path — путь к массиву (jsonpath.Resolve; "" — сам ответ уже
                // массив). scraper='browser' (свой headless Chrome) или 'firecrawl'
                // (платный фолбэк) — источник требует JS-рендер/не проходит anti-bot
                // защиту своим скрейпером (parser/internal/scrape/direct.go);
                // null (дефолт) — Direct.
                $arrayPath = null;
                $scraper = null;
                if (is_array($item)) {
                    $url = $item['url'] ?? '';
                    $arrayPath = $item['array_path'] ?? null;
                    $scraper = $item['scraper'] ?? null;
                } else {
                    $url = $item;
                }
                $url = is_string($url) ? trim($url) : '';

                // Пропускаем пустые, невалидные и слишком длинные (> 1000) URL.
                if ($url === '' || mb_strlen($url) > 1000) {
                    continue;
                }
                if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                    continue;
                }
                // Дедупликация в пределах входных данных (url уникален в БД).
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;

                DB::table('bank_source_urls')->updateOrInsert(
                    ['url' => $url],
                    [
                        'bank_id' => $bankId,
                        'category' => $category,
                        'array_path' => $arrayPath,
                        'scraper' => $scraper,
                        'email' => null, // адрес доставки лида задаётся позже вручную
                        'is_active' => true,
                        'last_parsed_at' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }
        }
    }

    /**
     * Результаты автообнаружения источников (по одному объекту на банк).
     *
     * Категория installment — рассрочка/исламское финансирование (Alif, Tawhid).
     *
     * @return array<int, array{slug: string, credit_urls: array<int, string>, installment_urls?: array<int, string>, deposit_urls: array<int, string>}>
     */
    private function sources(): array
    {
        return [
            [
                'slug' => 'eskhata',
                'credit_urls' => ['https://eskhata.com/loans/'],
                'deposit_urls' => ['https://eskhata.com/depo/'],
            ],
            [
                'slug' => 'dushanbe-city',
                'credit_urls' => [
                    'https://credit.dc.tj/',
                    'https://credit.dc.tj/consumer/',
                    'https://ipoteka.dc.tj/',
                ],
                'deposit_urls' => ['https://deposit.dc.tj/'],
            ],
            [
                'slug' => 'spitamen',
                'credit_urls' => ['https://www.spitamenbank.tj/ru/personal/products/credits/'],
                'deposit_urls' => ['https://www.spitamenbank.tj/ru/personal/products/deposits/'],
            ],
            [
                // JSON API, все языки (title/description/slogan/req_borrowers/
                // req_documents/review_period/loan_amount/loan_term/interest_rate
                // — каждое поле в *_ru/*_tg/*_en) в ОДНОМ ответе — проверено curl.
                // Старые HTML-URL (/tg/person/... для тадж., /person/... без
                // префикса для рус.) тоже рабочие, но требуют пары запросов и
                // риск рассинхрона — API надёжнее.
                // array_path='' — ответ уже сам массив (без обёртки),
                // проверено curl: [{"id":...,"title_ru":...,"title_tg":...},...].
                'slug' => 'arvand',
                'credit_urls' => [['url' => 'https://arvand.tj/person/api/credits/', 'array_path' => '']],
                'deposit_urls' => [['url' => 'https://arvand.tj/person/api/deposits/', 'array_path' => '']],
            ],
            [
                // Alif — исламский банк: классических кредитов нет, только рассрочка
                // Кураторская правка: installment = только Авто + Лизинг (прямые
                // страницы-продукты, ставка в калькуляторе/блоке). Салом исключён
                // (кредитная карта — вне MVP). Депозиты идут через discovery (/ru/deposit).
                'slug' => 'alif',
                'credit_urls' => [],
                'installment_urls' => [
                    'https://alif.tj/ru/auto/cars',
                    'https://alif.tj/ru/auto/leasing',
                ],
                'deposit_urls' => [],
            ],
            [
                'slug' => 'amonatbank',
                'credit_urls' => [
                    'https://amonatbonk.tj/ru/personal/loans/',
                    'https://amonatbonk.tj/ru/personal/hypothec/',
                ],
                'deposit_urls' => ['https://amonatbonk.tj/ru/personal/deposits/'],
            ],
            [
                // Без /ru/ — дефолтная ТАДЖИКСКАЯ версия (не ru!). Каноничный
                // URL источника обязан быть ru-версией (lang_url_rule на банке
                // выводит tj из неё, см. BankSeeder).
                'slug' => 'oriyonbank',
                'credit_urls' => ['https://oriyonbonk.tj/ru/individuals/loans'],
                'deposit_urls' => ['https://oriyonbonk.tj/ru/individuals/deposits'],
            ],
            [
                // credit/deposit — через discovery (инструкции). installment «Насия»
                // (рассрочка) — прямая страница внутри /loans, задаём явно. imon.tj —
                // за Cloudflare, свой скрейпер не проходит (см. BankParseInstructionSeeder).
                'slug' => 'imon',
                'credit_urls' => [],
                'installment_urls' => [['url' => 'https://imon.tj/loans/nasiya', 'scraper' => 'browser']],
                'deposit_urls' => [],
            ],
            [
                // Tawhidbank (исламский). Мурабаха-каталог /personal/financing — через
                // discovery. Авто-финансирование на отдельном пути — задаём явно.
                // Angular SPA, client-rendered — нужен JS-рендер (см. BankParseInstructionSeeder).
                'slug' => 'tawhidbank',
                'credit_urls' => [],
                'installment_urls' => [['url' => 'https://www.tawhidbank.tj/personal/auto-financing', 'scraper' => 'browser']],
                'deposit_urls' => [],
            ],
            [
                // Старые www.icb.tj URL шли через r.jina — Jina сейчас блокирует
                // ЦЕЛИКОМ этот домен (страница стучится на локальный IP,
                // триггерит anti-SSRF, воспроизводимо). Реальный источник —
                // публичный JSON API на ДРУГОМ хосте/порту (icb.tj:8384),
                // Jina вообще не участвует (прямой HTTP GET). type=ind — уже
                // структурный фильтр физлиц, не нужен текстовый разбор.
                // Полные данные продукта уже в JSON (title/description/
                // currency_attributes/min_term/max_term/requirement/document/
                // category) — детальных страниц не нужно, одна страница на всё.
                // array_path='data' — Laravel-пагинация {"data":[...],"links":...,"meta":...}.
                'slug' => 'icb',
                'credit_urls' => [['url' => 'https://icb.tj:8384/api/credit?type=ind', 'array_path' => 'data']],
                'deposit_urls' => [['url' => 'https://icb.tj:8384/api/deposit?type=ind', 'array_path' => 'data']],
            ],
            [
                'slug' => 'dbt',
                'credit_urls' => ['https://dbt.tj/ru/credits'],
                'deposit_urls' => ['https://dbt.tj/ru/deposits'],
            ],
            [
                'slug' => 'activbank',
                'credit_urls' => ['https://activbank.tj/ru/credits/chastnym-klientam'],
                'deposit_urls' => [
                    'https://activbank.tj/ru/deposits',
                    'https://activbank.tj/ru/deposit/srochnye-vklady',
                ],
            ],
            [
                'slug' => 'ibt',
                'credit_urls' => ['https://www.ibt.tj/credits/'],
                'deposit_urls' => ['https://www.ibt.tj/deposits/'],
            ],
            [
                // cbt.tj — client-rendered JS, свой скрейпер видит пустой shell
                // (см. BankParseInstructionSeeder).
                'slug' => 'cbt',
                'credit_urls' => [['url' => 'https://www.cbt.tj/credits', 'scraper' => 'browser']],
                'deposit_urls' => [['url' => 'https://www.cbt.tj/deposits', 'scraper' => 'browser']],
            ],
            [
                // SSB.tj — SPA грузит контент по AJAX, HTML-страница пустая до JS.
                // Раньше парсили саму SPA-страницу через рендер (r.jina) — попадали
                // на дефолтный таджикский вариант (язык не форсировался явно) вместо
                // русского. Реальный источник — сами AJAX-эндпоинты сайта: JSON,
                // без отдельных детальных страниц, language_id=2 форсирует русский.
                // array_path='results' — {"count":10,"next":null,"previous":null,"results":[...]}.
                'slug' => 'ssb',
                'credit_urls' => [['url' => 'https://webapi.ssb.tj/api/Credit/?language_id=2', 'array_path' => 'results']],
                'deposit_urls' => [['url' => 'https://webapi.ssb.tj/api/Deposit/?language_id=2', 'array_path' => 'results']],
            ],
            [
                'slug' => 'freedom',
                'credit_urls' => [
                    'https://credit.freedombank.tj',
                    'https://ipoteka.freedombank.tj',
                ],
                'deposit_urls' => [
                    'https://www.freedombank.tj/clients/deposits',
                    'https://www.freedombank.tj/clients/saving-account',
                ],
            ],
            [
                'slug' => 'humo',
                'credit_urls' => ['https://humo.tj/ru/credit'],
                'deposit_urls' => ['https://humo.tj/ru/deposit'],
            ],
            [
                'slug' => 'vasl',
                'credit_urls' => [],
                'deposit_urls' => [],
            ],
        ];
    }
}
