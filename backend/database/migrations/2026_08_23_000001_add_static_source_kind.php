<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * kind='static_source' (bank_parse_instructions) — URL продукта уже точно
 * известен (обычный discovery с поиском ссылок тут не нужен), но страница
 * содержит НЕСКОЛЬКО отдельных продуктов целиком (не тарифная сетка одного
 * продукта, не каталог со ссылками на подстраницы) — напр. deposit.dc.tj
 * (Душанбе Сити) и humo.tj/ru/credit|deposit (Хумо, кнопки — JS-заглушки без
 * реального href на подстраницы).
 *
 * Регистрация источника в bank_source_urls для такой инструкции идёт БЕЗ
 * единого AI-вызова (discover.go registerStaticSources) — экономит
 * discovery-прогон там, где и так заранее известно, что ссылок искать не
 * нужно. bank_source_urls.extract_mode='static_source' — сигнал cmd/parser
 * использовать отдельный лёгкий экстрактор (StaticSourceExtractor, свой
 * промпт БЕЗ catalog-режима вообще — устраняет саму возможность спутать
 * "несколько продуктов на странице" с "каталог ссылок", как ловили на
 * ипотеке Амонатбанка) вместо общего Extract().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_kind');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_kind
            CHECK (kind IN ('product_discovery','rates','static_source'))");

        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_category');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_category CHECK (
            (kind = 'rates' AND category IS NULL)
            OR (kind IN ('product_discovery','static_source') AND category IN ('credit','deposit','installment'))
        )");

        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->string('extract_mode', 24)->nullable()->after('notes');
        });
        DB::statement("ALTER TABLE bank_source_urls ADD CONSTRAINT chk_bsu_extract_mode
            CHECK (extract_mode IS NULL OR extract_mode IN ('static_source'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_source_urls DROP CONSTRAINT IF EXISTS chk_bsu_extract_mode');
        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->dropColumn('extract_mode');
        });

        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_category');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_category CHECK (
            (kind = 'rates' AND category IS NULL)
            OR (kind = 'product_discovery' AND category IN ('credit','deposit','installment'))
        )");

        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_kind');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_kind CHECK (kind IN ('product_discovery','rates'))");
    }
};
