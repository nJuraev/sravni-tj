<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расширенный контент продукта, извлекаемый парсером со страницы банка:
 *  - key_conditions_ru/tg — буллеты реальных условий («0% предоплаты»,
 *    «комиссия 0% физлицам / 1% бизнес-сегменту»), которые не умещаются в
 *    ставку/сумму/срок и терялись в пересказе description_*;
 *  - documents_ru/tg — минимальный пакет документов («Паспорт», «ИНН» …);
 *  - source_url — прямая ссылка на страницу ИМЕННО этого продукта на сайте
 *    банка (не общая страница источника — для index-режима это страница
 *    конкретной детали, а не страница-каталог).
 *
 * Оба списка — jsonb-массив строк, nullable (не все страницы банков дают
 * такой структурированный текст — деградирует так же, как description_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->jsonb('key_conditions_ru')->nullable()->after('description_tg');
            $table->jsonb('key_conditions_tg')->nullable()->after('key_conditions_ru');
            $table->jsonb('documents_ru')->nullable()->after('key_conditions_tg');
            $table->jsonb('documents_tg')->nullable()->after('documents_ru');
            $table->string('source_url', 1000)->nullable()->after('documents_tg');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['key_conditions_ru', 'key_conditions_tg', 'documents_ru', 'documents_tg', 'source_url']);
        });
    }
};
