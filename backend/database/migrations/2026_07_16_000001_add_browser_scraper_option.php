<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * scraper='browser' — свой headless-Chrome по CDP (internal/scrape/browser.go),
 * замена платному Firecrawl для того же класса источников (client-rendered
 * SPA, anti-bot). Firecrawl остаётся как ручной фолбэк на случай, если Browser
 * не проходит конкретную защиту, которую проходит Firecrawl (и наоборот) —
 * поэтому расширяем CHECK, а не заменяем значение.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bank_source_urls DROP CONSTRAINT IF EXISTS chk_bsu_scraper');
        DB::statement("ALTER TABLE bank_source_urls ADD CONSTRAINT chk_bsu_scraper CHECK (scraper IS NULL OR scraper IN ('firecrawl', 'browser'))");

        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_scraper');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_scraper CHECK (scraper IS NULL OR scraper IN ('firecrawl', 'browser'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_scraper');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_scraper CHECK (scraper IS NULL OR scraper IN ('firecrawl'))");

        DB::statement('ALTER TABLE bank_source_urls DROP CONSTRAINT IF EXISTS chk_bsu_scraper');
        DB::statement("ALTER TABLE bank_source_urls ADD CONSTRAINT chk_bsu_scraper CHECK (scraper IS NULL OR scraper IN ('firecrawl'))");
    }
};
