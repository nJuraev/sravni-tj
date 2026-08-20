<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skip-if-unchanged кэш парсера (parser/internal/parser): не звать AI повторно,
 * если markdown/JSON, который бы ушёл в extract, побайтово совпадает с прошлым
 * успешным прогоном.
 *
 * - bank_source_urls.last_markdown_hash — sha256 markdown ВЕРХНЕУРОВНЕВОЙ
 *   страницы задачи (task.URL), с которой парсер стартует. Используется
 *   вместе с products.source_url, чтобы понять, был ли это прямой путь
 *   (products напрямую со страницы) или index-режим (ссылки на детали) —
 *   отдельного поля под это не заводим.
 * - products.content_hash — sha256 текста, из которого КОНКРЕТНО этот продукт
 *   извлечён (для прямого пути — та же страница, что и выше; для index-режима
 *   — markdown детальной страницы, products.source_url).
 *
 * Оба nullable — существующие строки (и банки, где кэш ещё не сработал ни
 * разу) остаются NULL, что естественно трактуется парсером как "нет кэша,
 * парсить с нуля" без отдельного бэкафилла.
 *
 * Индекс на (source_url_id, source_url) — раньше такого не было (только
 * уникальный (source_url_id, external_key) для upsert), а именно по паре
 * source_url_id+source_url парсер теперь ищет "видели ли мы уже этот
 * конкретный текст" перед вызовом AI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->string('last_markdown_hash', 64)->nullable()->after('array_path');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('source_url');
            $table->index(['source_url_id', 'source_url'], 'idx_products_source_url_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_source_url_lookup');
            $table->dropColumn('content_hash');
        });

        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->dropColumn('last_markdown_hash');
        });
    }
};
