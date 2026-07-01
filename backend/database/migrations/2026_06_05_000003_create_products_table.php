<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица products — продукты (кредиты/депозиты).
 *
 * Денормализованные агрегаты ставки/суммы/срока для быстрых фильтров витрины
 * + детальная тарифная сетка в product_rates (Вариант A, schema.md §7).
 *
 * Ключевые решения:
 *  - Один продукт = одна валюта (мультивалютный продукт банка дробится парсером
 *    на N записей); отдельной таблицы валют нет — поле currency (VARCHAR + CHECK).
 *  - Стартовый статус = 'draft' (НЕ 'active').
 *  - features — jsonb с GIN-индексом (jsonb_path_ops) под фильтры по булевым фичам.
 *  - Деньги/ставки — NUMERIC (decimal) с явной точностью, не float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');

            // FK → banks(id). Удалили банк — ушли его продукты.
            $table->foreignId('bank_id')
                ->constrained('banks')
                ->cascadeOnDelete();

            // FK → bank_source_urls(id). Источник могли отключить/удалить —
            // продукт остаётся, теряет привязку (SET NULL).
            $table->foreignId('source_url_id')
                ->nullable()
                ->constrained('bank_source_urls')
                ->nullOnDelete();

            // Стабильный ключ продукта в рамках источника (основа upsert/идемпотентности).
            $table->string('external_key', 255);

            // Категория: credit|deposit|installment (VARCHAR + CHECK).
            // installment — рассрочка / исламское финансирование (Alif, Tawhid):
            // без процентной ставки, через наценку.
            $table->string('category', 16);

            // Мультиязычная пара названий: хотя бы одно задано (CHECK).
            $table->string('name_ru', 255)->nullable();
            $table->string('name_tg', 255)->nullable();

            // Мультиязычные описания.
            $table->text('description_ru')->nullable();
            $table->text('description_tg')->nullable();

            // Статус продукта: active|draft|hidden|outdated.
            // Стартовый DEFAULT = 'draft' (утверждённое решение, переопределяет
            // рекомендацию schema.md §10.1 про 'active').
            $table->string('status', 16)->default('draft');

            // Валюта продукта: TJS|USD|EUR (VARCHAR + CHECK).
            $table->string('currency', 3);

            // Ставки, % годовых. NUMERIC(6,3): до 100.000 с тремя знаками.
            $table->decimal('rate_min', 6, 3);
            $table->decimal('rate_max', 6, 3);

            // Суммы в номинале валюты. NUMERIC(18,2).
            // amount_min nullable: NULL = минимальная сумма не указана (частый случай у кредитов).
            $table->decimal('amount_min', 18, 2)->nullable();
            $table->decimal('amount_max', 18, 2)->nullable();

            // Сроки в месяцах.
            $table->integer('term_min')->nullable();
            $table->integer('term_max')->nullable();

            // Булевы признаки (online_application, no_guarantor, capitalization,
            // replenishable, early_withdrawal). jsonb с GIN-индексом ниже.
            $table->jsonb('features')->default(DB::raw("'{}'::jsonb"));

            // Время извлечения данных AI.
            $table->timestampTz('parsed_at')->nullable();

            $table->timestampsTz();
        });

        Schema::table('products', function (Blueprint $table) {
            // Идемпотентность upsert парсера: один продукт на (источник + ключ).
            $table->unique(['source_url_id', 'external_key'], 'uq_products_source_key');

            // Основной составной индекс под GET /api/products
            // (фильтр status='active' + category + currency).
            $table->index(['status', 'category', 'currency'], 'idx_products_list');

            // B-tree по денормализованным ставкам — фильтр/сортировка «ставка от/до».
            $table->index('rate_min', 'idx_products_rate');
            $table->index('rate_max', 'idx_products_rate_max');

            // Индекс по FK банка.
            $table->index('bank_id', 'idx_products_bank');
        });

        // GIN-индекс по features (jsonb_path_ops) — фильтры вида
        // features @> '{"online_application": true}'. Создаётся сырым SQL,
        // т.к. Laravel Schema Builder не выражает класс операторов GIN.
        DB::statement('CREATE INDEX idx_products_features_gin ON products USING gin (features jsonb_path_ops)');

        // CHECK-ограничения: enum-поля + числовые инварианты (анти-галлюцинации на уровне БД).
        DB::statement("ALTER TABLE products ADD CONSTRAINT chk_products_category CHECK (category IN ('credit','deposit','installment'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT chk_products_status CHECK (status IN ('active','draft','hidden','outdated'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT chk_products_currency CHECK (currency IN ('TJS','USD','EUR'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_rate_range CHECK (rate_min >= 0 AND rate_max <= 100 AND rate_max >= rate_min)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_amount CHECK ((amount_min IS NULL OR amount_min > 0) AND (amount_max IS NULL OR amount_min IS NULL OR amount_max >= amount_min))');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_term CHECK ((term_min IS NULL OR term_min > 0) AND (term_max IS NULL OR (term_max > 0 AND (term_min IS NULL OR term_max >= term_min))))');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_name_present CHECK (name_ru IS NOT NULL OR name_tg IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_category');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_status');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_currency');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_rate_range');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_amount');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_term');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_name_present');
        DB::statement('DROP INDEX IF EXISTS idx_products_features_gin');

        Schema::dropIfExists('products');
    }
};
