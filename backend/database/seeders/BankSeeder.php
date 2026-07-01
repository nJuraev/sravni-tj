<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Сидер банков Таджикистана.
 *
 * Источник списка и контактов — реестр НБТ:
 * https://nbt.tj/ru/banking_system/banks.php
 * Сайты — из утверждённого списка заказчика.
 *
 * ВАЖНО: contact_email — это ПУБЛИЧНЫЙ справочный email из реестра НБТ,
 * НЕ адрес доставки заявок. Адрес доставки лида задаётся отдельно на
 * bank_source_urls.email (по категории) и здесь НЕ заполняется.
 *
 * Названия на таджикском (name_tg) и адреса — предварительные, требуют сверки.
 * Сидер идемпотентен (updateOrInsert по slug).
 */
class BankSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach ($this->banks() as $bank) {
            DB::table('banks')->updateOrInsert(
                ['slug' => $bank['slug']],
                // Дефолты идут ПЕРВЫМИ, чтобы статус/партнёрство можно было
                // переопределить в самой записи банка (напр. vasl → inactive).
                array_merge(['status' => 'active', 'is_partner' => false], $bank, [
                    'updated_at' => $now,
                    'created_at' => $now,
                ]),
            );
        }
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function banks(): array
    {
        return [
            [
                'slug' => 'eskhata',
                'name_ru' => 'ОАО «Банк Эсхата»',
                'name_tg' => 'ҶСК «Бонки Эсхата»',
                'website' => 'https://eskhata.com/',
                'phone' => '(+992 44) 600-0-600',
                'address_ru' => 'г. Худжанд, ул. Гагарина, 135',
                'address_tg' => null,
                'contact_email' => 'info2@eskhata.tj',
            ],
            [
                'slug' => 'dushanbe-city',
                'name_ru' => 'ЗАО «Душанбе Сити Банк»',
                'name_tg' => 'ҶСП «Бонки Душанбе Сити»',
                'website' => 'https://dc.tj/',
                'phone' => '(+992) 41 800 99 88',
                'address_ru' => 'г. Душанбе, р. Фирдавси, ул. Сохили 5',
                'address_tg' => null,
                'contact_email' => 'info1@dc.tj',
            ],
            [
                'slug' => 'spitamen',
                'name_ru' => 'ЗАО «Спитамен Банк»',
                'name_tg' => 'ҶСП «Бонки Спитамен»',
                'website' => 'https://www.spitamenbank.tj/ru/personal/',
                'phone' => '(+992 44) 600-10-92',
                'address_ru' => 'г. Душанбе, ул. Бободжон Гафуров 45',
                'address_tg' => null,
                'contact_email' => 'info@spitamen.com',
            ],
            [
                'slug' => 'arvand',
                'name_ru' => 'ЗАО Банк «Арванд»',
                'name_tg' => 'ҶСП Бонки «Арванд»',
                'website' => 'https://arvand.tj/',
                'phone' => '(+992 44) 600-14-00',
                'address_ru' => 'Согдийская область, г. Худжанд, проспект Исмоили Сомони 1 А',
                'address_tg' => null,
                'contact_email' => 'office@arvand.tj',
            ],
            [
                'slug' => 'alif',
                'name_ru' => 'ОАО «Алиф Банк»',
                'name_tg' => 'ҶСК «Бонки Алиф»',
                'website' => 'https://alif.tj/',
                'phone' => '(+992 44) 625-73-71',
                'address_ru' => 'г. Душанбе, ул. Багаутдинова 9',
                'address_tg' => null,
                'contact_email' => 'info@alif.tj',
            ],
            [
                'slug' => 'amonatbank',
                'name_ru' => 'ГУП СБ РТ «Амонатбанк»',
                'name_tg' => 'КВД БА ҶТ «Амонатбонк»',
                'website' => 'https://amonatbonk.tj/ru/',
                'phone' => '(+992 37) 227-55-50',
                'address_ru' => 'г. Душанбе, проспект Рудаки, 105',
                'address_tg' => null,
                'contact_email' => 'info@amonatbonk.tj',
            ],
            [
                'slug' => 'oriyonbank',
                'name_ru' => 'ОАО «Ориёнбонк»',
                'name_tg' => 'ҶСК «Ориёнбонк»',
                'website' => 'https://oriyonbonk.tj/',
                'phone' => '(+992 37) 221-11-09',
                'address_ru' => 'г. Душанбе, проспект Рудаки, 95/1',
                'address_tg' => null,
                'contact_email' => 'info@orienbank.com',
            ],
            [
                'slug' => 'imon',
                'name_ru' => 'ЗАО Банк «Имон Интернешнл»',
                'name_tg' => 'ҶСП Бонки «Имон Интернешнл»',
                'website' => 'https://imon.tj/',
                'phone' => '(+992 3422) 25744',
                'address_ru' => 'Согдийская область, город Худжанд, микрорайон 17, 2',
                'address_tg' => null,
                'contact_email' => 'info@imon.tj',
            ],
            [
                'slug' => 'tawhidbank',
                'name_ru' => 'ОАО «Тавхидбанк»',
                'name_tg' => 'ҶСК «Бонки Тавҳид»',
                'website' => 'http://www.tawhidbank.tj/',
                'phone' => '(+992 44) 600-47-70',
                'address_ru' => 'г. Душанбе, ул. С. Айни 4/1',
                'address_tg' => null,
                'contact_email' => 'info@tawhidbank.tj',
            ],
            [
                'slug' => 'icb',
                'name_ru' => 'ЗАО «Инвестиционно-Кредитный Банк Таджикистан»',
                'name_tg' => 'ҶСП «Бонки Сармоягузории Қарзии Тоҷикистон»',
                'website' => 'https://www.icb.tj',
                'phone' => '(+992 37) 227-91-70',
                'address_ru' => 'г. Душанбе, район И. Сомони, ул. Пушкина 10',
                'address_tg' => null,
                'contact_email' => 'info@icb.tj',
            ],
            [
                'slug' => 'dbt',
                'name_ru' => 'ЗАО «Банк развития Таджикистана»',
                'name_tg' => 'ҶСП «Бонки рушди Тоҷикистон»',
                'website' => 'https://dbt.tj/ru',
                'phone' => '(+992 44) 600-55-65',
                'address_ru' => 'г. Душанбе, ул. А. Пушкина, 20',
                'address_tg' => null,
                'contact_email' => 'info@brt.tj',
            ],
            [
                'slug' => 'activbank',
                'name_ru' => 'ЗАО «Актив Банк»',
                'name_tg' => 'ҶСП «Актив Банк»',
                'website' => 'https://www.activbank.tj',
                'phone' => '(+992 44) 640-50-50',
                'address_ru' => 'г. Душанбе, район Сино, улица Дилкушо 26/1',
                'address_tg' => null,
                'contact_email' => 'info@activbank.tj',
            ],
            [
                'slug' => 'ibt',
                'name_ru' => 'ЗАО «Международный банк Таджикистана»',
                'name_tg' => 'ҶСП «Бонки байналмилалии Тоҷикистон»',
                'website' => 'https://www.ibt.tj',
                'phone' => '(+992 44) 640 50 88',
                'address_ru' => 'г. Душанбе, район Шохмансур, улица Бухоро 27',
                'address_tg' => null,
                'contact_email' => 'info@ibt.tj',
            ],
            [
                'slug' => 'cbt',
                'name_ru' => 'ОАО «КоммерцбанкТаджикистана»',
                'name_tg' => 'ҶСК «Бонки тиҷоратии Тоҷикистон»',
                'website' => 'https://www.cbt.tj',
                'phone' => '(+992) (44) 630-88-88',
                'address_ru' => 'г. Душанбе, район И. Сомони, улица Бохтар 37/1',
                'address_tg' => null,
                'contact_email' => 'info@cbt.tj',
            ],
            [
                'slug' => 'ssb',
                'name_ru' => 'ГУП ПЭБТ «Саноатсодиротбонк»',
                'name_tg' => 'КВД БСИ «Саноатсодиротбонк»',
                'website' => 'https://www.ssb.tj',
                'phone' => '(+992) (37) 233-37-09',
                'address_ru' => 'г. Душанбе, проспект Саади Ширази 21',
                'address_tg' => null,
                'contact_email' => 'info@ssb.tj',
            ],
            [
                'slug' => 'freedom',
                'name_ru' => 'ЗАО «Фридом банк Таджикистан»',
                'name_tg' => 'ҶСП «Фридом банк Тоҷикистон»',
                'website' => 'https://www.freedombank.tj',
                'phone' => null,
                'address_ru' => 'г. Душанбе, проспект Рудаки 81B',
                'address_tg' => null,
                'contact_email' => 'info@freedombank.tj',
            ],
            [
                'slug' => 'humo',
                'name_ru' => 'ЗАО «Хумо Бонк»',
                'name_tg' => 'ҶСП «Бонки Ҳумо»',
                'website' => 'https://www.humo.tj',
                'phone' => '(+992 37) 239-19-56',
                'address_ru' => 'г. Душанбе, р. Фирдавси, улица Н. Карабоев 148/1',
                'address_tg' => null,
                'contact_email' => 'office@humo.tj',
            ],
            [
                'slug' => 'vasl',
                'name_ru' => 'ЗАО «Васл Бонк»',
                'name_tg' => 'ҶСП «Бонки Васл»',
                'website' => 'https://www.vasl.tj',
                'phone' => '+992 44 610 45 45',
                'address_ru' => 'г. Душанбе, район Сино, ул. Мухаммадиев 11/6',
                'address_tg' => null,
                'contact_email' => 'info@vasl.tj',
                // Нет нужных нам продуктов (кредиты/депозиты для физлиц) — скрыт.
                'status' => 'inactive',
            ],
        ];
    }
}
