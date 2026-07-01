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
            foreach ($entry['credit_urls'] as $url) {
                $pairs[] = ['credit', $url];
            }
            foreach (($entry['installment_urls'] ?? []) as $url) {
                $pairs[] = ['installment', $url];
            }
            foreach ($entry['deposit_urls'] as $url) {
                $pairs[] = ['deposit', $url];
            }

            foreach ($pairs as [$category, $url]) {
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
                'slug' => 'arvand',
                'credit_urls' => ['https://arvand.tj/tg/person/credits/'],
                'deposit_urls' => ['https://arvand.tj/tg/person/deposits/'],
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
                'slug' => 'oriyonbank',
                'credit_urls' => ['https://oriyonbonk.tj/individuals/loans'],
                'deposit_urls' => ['https://oriyonbonk.tj/individuals/deposits'],
            ],
            [
                // credit/deposit — через discovery (инструкции). installment «Насия»
                // (рассрочка) — прямая страница внутри /loans, задаём явно.
                'slug' => 'imon',
                'credit_urls' => [],
                'installment_urls' => ['https://imon.tj/loans/nasiya'],
                'deposit_urls' => [],
            ],
            [
                // Tawhidbank (исламский). Мурабаха-каталог /personal/financing — через
                // discovery. Авто-финансирование на отдельном пути — задаём явно.
                'slug' => 'tawhidbank',
                'credit_urls' => [],
                'installment_urls' => ['https://www.tawhidbank.tj/personal/auto-financing'],
                'deposit_urls' => [],
            ],
            [
                'slug' => 'icb',
                'credit_urls' => ['https://www.icb.tj/RU/Credit'],
                'deposit_urls' => ['https://www.icb.tj/RU/Deposit'],
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
                'slug' => 'cbt',
                'credit_urls' => ['https://www.cbt.tj/credits'],
                'deposit_urls' => ['https://www.cbt.tj/deposits'],
            ],
            [
                'slug' => 'ssb',
                'credit_urls' => [
                    'https://www.ssb.tj/ru/?type=1',
                    'https://www.ssb.tj/Credit/?type=1',
                ],
                'deposit_urls' => ['https://www.ssb.tj/ru/Deposit'],
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
