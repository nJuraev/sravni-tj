<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица bank_currency_rates — курсы валют банков.
 *
 * Заполняется парсером курсов (cmd/rates). По одному курсу на сочетание
 * банк × валюта × категория (cash/transfer) × дата. Операции покупки/продажи
 * хранятся в одной строке (buy/sell), т.к. на странице банка идут парой.
 *
 * Валюта — свободный код (USD/EUR/RUB/CNY/…), не ограничен enum продуктов:
 * для переводов и кассы банки котируют больше валют, чем TJS/USD/EUR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_currency_rates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('bank_id')
                ->constrained('banks')
                ->cascadeOnDelete();

            // ISO-код валоты (USD, EUR, RUB, CNY, …). База котировки — TJS.
            $table->string('currency', 3);

            // Категория курса: cash (касса/наличные) | transfer (денежные переводы).
            $table->string('category', 16);

            // Курс покупки банком (банк покупает валюту у клиента) и продажи (продаёт клиенту).
            // NULL = банк не котирует эту операцию для данной валюты/категории.
            $table->decimal('buy', 12, 4)->nullable();
            $table->decimal('sell', 12, 4)->nullable();

            // Дата, на которую действует курс (банки публикуют посуточно).
            $table->date('rate_date');

            // Когда парсер снял курс.
            $table->timestampTz('parsed_at')->nullable();

            $table->timestampsTz();
        });

        Schema::table('bank_currency_rates', function (Blueprint $table) {
            // Один курс на банк×валюту×категорию×дату (повторный парс = upsert).
            $table->unique(['bank_id', 'currency', 'category', 'rate_date'], 'uq_bcr_key');
            $table->index('bank_id', 'idx_bcr_bank');
            // Запрос витрины: свежие курсы по валюте/категории.
            $table->index(['currency', 'category', 'rate_date'], 'idx_bcr_lookup');
        });

        // category — enum.
        DB::statement("ALTER TABLE bank_currency_rates ADD CONSTRAINT chk_bcr_category CHECK (category IN ('cash','transfer'))");
        // Хотя бы одна сторона котировки задана.
        DB::statement('ALTER TABLE bank_currency_rates ADD CONSTRAINT chk_bcr_any_side CHECK (buy IS NOT NULL OR sell IS NOT NULL)');
        // Курсы положительны.
        DB::statement('ALTER TABLE bank_currency_rates ADD CONSTRAINT chk_bcr_positive CHECK ((buy IS NULL OR buy > 0) AND (sell IS NULL OR sell > 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_currency_rates DROP CONSTRAINT IF EXISTS chk_bcr_positive');
        DB::statement('ALTER TABLE bank_currency_rates DROP CONSTRAINT IF EXISTS chk_bcr_any_side');
        DB::statement('ALTER TABLE bank_currency_rates DROP CONSTRAINT IF EXISTS chk_bcr_category');

        Schema::dropIfExists('bank_currency_rates');
    }
};
