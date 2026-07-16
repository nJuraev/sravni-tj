<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * scraper — выбор скрейпера для конкретного источника, НЕ глобальный env
 * (SCRAPER_PROVIDER больше не переключает всё сразу).
 *
 * NULL (по умолчанию) — свой скрейпер (прямой HTTP GET + readability-lite,
 * internal/scrape/direct.go): бесплатно, без внешних сервисов, хватает для
 * server-rendered страниц (большинство банков).
 *
 * 'firecrawl' — источник требует полноценный JS-рендер (client-rendered SPA:
 * Angular/React) либо стоит за Cloudflare-защитой, которую прямой GET не
 * проходит. Такие источники курируются вручную (см. BankSourceUrlSeeder /
 * BankParseInstructionSeeder) — таджикский банк-сайт не начинает и не
 * перестаёт быть SPA сам по себе, руками выставленный флаг стабильнее
 * автоопределения.
 *
 * Колонка добавлена И на bank_source_urls (сам парсинг продукта, internal/
 * parser/parser.go), И на bank_parse_instructions (discovery старт-страницы
 * И страницы курсов без rate_rule, internal/discover, internal/rates) —
 * каждый из трёх пайплайнов скрейпит независимо и может нуждаться в разных
 * скрейперах даже для одного банка (напр. каталог — server-rendered, а
 * страница курсов того же банка — SPA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->string('scraper', 16)->nullable()->after('array_path');
        });
        Schema::table('bank_parse_instructions', function (Blueprint $table) {
            $table->string('scraper', 16)->nullable()->after('css_hints');
        });

        DB::statement("ALTER TABLE bank_source_urls ADD CONSTRAINT chk_bsu_scraper CHECK (scraper IS NULL OR scraper IN ('firecrawl'))");
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_scraper CHECK (scraper IS NULL OR scraper IN ('firecrawl'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_scraper');
        DB::statement('ALTER TABLE bank_source_urls DROP CONSTRAINT IF EXISTS chk_bsu_scraper');

        Schema::table('bank_parse_instructions', function (Blueprint $table) {
            $table->dropColumn('scraper');
        });
        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->dropColumn('scraper');
        });
    }
};
