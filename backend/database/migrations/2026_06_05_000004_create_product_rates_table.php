<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица product_rates — нормализованная тарифная сетка (Вариант A, schema.md §7).
 *
 * Строка на комбинацию диапазонов срок×сумма → ставка.
 * Валюта наследуется от products.currency (одна валюта на продукт по контракту AI).
 * Источник истины по ставкам; products.rate_min/rate_max денормализуются
 * парсером как MIN/MAX(rate) в той же транзакции.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_rates', function (Blueprint $table) {
            $table->bigIncrements('id');

            // FK → products(id). Удалили/перезаписали продукт — сетка пересоздаётся.
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            // Границы срока в месяцах. NULL = тариф не зависит от срока.
            $table->integer('term_min')->nullable();
            $table->integer('term_max')->nullable();

            // Границы суммы. NULL = тариф не зависит от суммы.
            $table->decimal('amount_min', 18, 2)->nullable();
            $table->decimal('amount_max', 18, 2)->nullable();

            // Годовая ставка для этой комбинации, % (NUMERIC(6,3)).
            $table->decimal('rate', 6, 3);

            // Только created_at (сетка replace-all, без updated_at).
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::table('product_rates', function (Blueprint $table) {
            // Выборка сетки для карточки и пересчёта агрегатов.
            $table->index('product_id', 'idx_rates_product');
            // Фильтр/сортировка по конкретной ставке тарифа.
            $table->index('rate', 'idx_rates_rate');
            // Точечный подбор тарифа калькулятором по сумме/сроку.
            $table->index(
                ['product_id', 'term_min', 'term_max', 'amount_min', 'amount_max'],
                'idx_rates_lookup'
            );
        });

        // CHECK: ставка 0..100 + согласованность диапазонов срока/суммы (с учётом NULL).
        DB::statement('ALTER TABLE product_rates ADD CONSTRAINT chk_rates_rate CHECK (rate >= 0 AND rate <= 100)');
        DB::statement('ALTER TABLE product_rates ADD CONSTRAINT chk_rates_term CHECK ((term_min IS NULL OR term_min > 0) AND (term_max IS NULL OR (term_max > 0 AND (term_min IS NULL OR term_max >= term_min))))');
        DB::statement('ALTER TABLE product_rates ADD CONSTRAINT chk_rates_amount CHECK ((amount_min IS NULL OR amount_min > 0) AND (amount_max IS NULL OR (amount_max > 0 AND (amount_min IS NULL OR amount_max >= amount_min))))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_rates DROP CONSTRAINT IF EXISTS chk_rates_rate');
        DB::statement('ALTER TABLE product_rates DROP CONSTRAINT IF EXISTS chk_rates_term');
        DB::statement('ALTER TABLE product_rates DROP CONSTRAINT IF EXISTS chk_rates_amount');

        Schema::dropIfExists('product_rates');
    }
};
