<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Метки последнего УСПЕШНОГО (>0 записанных строк) прогона парсера по банку —
 * отдельно для продуктов (credit/deposit/installment) и курсов валют.
 *
 * Проставляются ТОЛЬКО парсером (Go, store.TouchBankProductsUpdated/
 * TouchBankRatesUpdated), только когда прогон реально что-то сохранил — не
 * при каждом запуске (в отличие от bank_parse_instructions.last_run_at,
 * который тачится безусловно и не годится для детекции "парсер сломался,
 * но тихо").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->timestampTz('products_updated_at')->nullable()->after('lang_url_rule_params');
            $table->timestampTz('rates_updated_at')->nullable()->after('products_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->dropColumn(['products_updated_at', 'rates_updated_at']);
        });
    }
};
