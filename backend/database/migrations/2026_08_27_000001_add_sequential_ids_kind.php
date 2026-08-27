<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * kind='sequential_ids' (bank_parse_instructions) — банк держит детальные
 * страницы продуктов на числовых id вида {start_url}/1, {start_url}/2, ...
 * (напр. cbt.tj/credits/1, /credits/2, ...), БЕЗ каталога со ссылками и БЕЗ
 * настоящего HTTP 404 на несуществующем id (SPA всегда отдаёт 200 с одной и
 * той же пустой обёрткой — см. discover.go processSequentialIDs). Общая
 * страница-каталог (напр. www.cbt.tj/credits) при этом НЕ содержит полных
 * условий (нет процентной ставки) — только детальные страницы.
 *
 * discover.go перебирает id=1..cap (safety-предел, не бизнес-лимит), считает
 * "промахом" ответ, идентичный БАЗОВОМУ пробнику ({start_url}/0 — заведомо
 * невалидный id, самокалибровка без хардкода языковых маркеров), и
 * останавливается после 2 промахов подряд — аналог "2 подряд 404" из
 * задачи, но по контенту, а не по HTTP-коду. Найденные id регистрируются в
 * bank_source_urls как обычные источники (БЕЗ AI-вызова на этом шаге — тот
 * же принцип экономии, что у static_source), дальше их парсит cmd/parser
 * как любой другой источник.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_kind');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_kind
            CHECK (kind IN ('product_discovery','rates','static_source','sequential_ids'))");

        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_category');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_category CHECK (
            (kind = 'rates' AND category IS NULL)
            OR (kind IN ('product_discovery','static_source','sequential_ids') AND category IN ('credit','deposit','installment'))
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_category');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_category CHECK (
            (kind = 'rates' AND category IS NULL)
            OR (kind IN ('product_discovery','static_source') AND category IN ('credit','deposit','installment'))
        )");

        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_kind');
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_kind
            CHECK (kind IN ('product_discovery','rates','static_source'))");
    }
};
