<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица bank_parse_instructions — инструкции «где и как» искать на сайте банка.
 *
 * Заменяет ручной seed bank_source_urls. По ней работает discovery-парсер
 * (cmd/discover): берёт start_url + подсказки и находит ссылки на продукты,
 * наполняя bank_source_urls. kind='rates' задаёт страницу курсов для cmd/rates.
 *
 * Один банк → несколько инструкций (по категориям продуктов + одна на курсы).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_parse_instructions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('bank_id')
                ->constrained('banks')
                ->cascadeOnDelete();

            // Тип инструкции: product_discovery (поиск страниц продуктов) | rates (курсы валют).
            $table->string('kind', 24)->default('product_discovery');

            // Категория продукта для discovery: credit|deposit|installment.
            // NULL для kind='rates' (категория там не применима).
            $table->string('category', 16)->nullable();

            // Стартовая страница, с которой начинается обход (главная/раздел/страница курсов).
            $table->string('start_url', 1000);

            // Секции меню, где искать продукты (подсказка AI): ["Кредиты","Депозиты"].
            $table->jsonb('menu_sections')->nullable();

            // Опциональные CSS-селекторы-подсказки (если меню нестандартное).
            $table->jsonb('css_hints')->nullable();

            // Свободная текстовая подсказка AI (например «продукты в подвале под "Услуги"»).
            $table->text('notes')->nullable();

            // Discovery берёт только активные инструкции.
            $table->boolean('is_active')->default(true);

            // Когда последний раз отрабатывала эта инструкция.
            $table->timestampTz('last_run_at')->nullable();

            $table->timestampsTz();
        });

        Schema::table('bank_parse_instructions', function (Blueprint $table) {
            $table->index('bank_id', 'idx_bpi_bank');
        });

        DB::statement('CREATE INDEX idx_bpi_active ON bank_parse_instructions (is_active) WHERE is_active = true');

        // kind — enum.
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_kind CHECK (kind IN ('product_discovery','rates'))");
        // category — enum продукта или NULL; для rates обязан быть NULL, для discovery — задан.
        DB::statement("ALTER TABLE bank_parse_instructions ADD CONSTRAINT chk_bpi_category CHECK (
            (kind = 'rates' AND category IS NULL)
            OR (kind = 'product_discovery' AND category IN ('credit','deposit','installment'))
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_category');
        DB::statement('ALTER TABLE bank_parse_instructions DROP CONSTRAINT IF EXISTS chk_bpi_kind');
        DB::statement('DROP INDEX IF EXISTS idx_bpi_active');

        Schema::dropIfExists('bank_parse_instructions');
    }
};
