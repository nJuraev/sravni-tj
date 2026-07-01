<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица leads — заявки.
 *
 * Ключевые решения:
 *  - consent boolean + CHECK (consent = true): согласие обязательно на уровне БД
 *    (жёсткий инвариант, дублирует валидацию Laravel → 422 без согласия).
 *  - bank_id ON DELETE RESTRICT: нельзя удалить банк с заявками (бизнес-ценные данные).
 *    bank_id денормализован (NOT NULL): заявка должна уйти на email банка даже если
 *    product_id обнулится.
 *  - product_id ON DELETE SET NULL: продукт может быть перепарсен/удалён,
 *    заявка как факт обращения остаётся.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->bigIncrements('id');

            // FK → products(id) ON DELETE SET NULL.
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            // FK → banks(id) ON DELETE RESTRICT (нельзя удалить банк с заявками).
            $table->foreignId('bank_id')
                ->constrained('banks')
                ->restrictOnDelete();

            // PII: ФИО и телефон заявителя.
            $table->string('full_name', 255);
            $table->string('phone', 32);

            // Согласие на обработку. Без DEFAULT — должно быть передано явно;
            // CHECK ниже гарантирует, что значение всегда true.
            $table->boolean('consent');

            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->index('bank_id', 'idx_leads_bank');
            $table->index('product_id', 'idx_leads_product');
            // Выгрузка/аналитика по времени.
            $table->index('created_at', 'idx_leads_created');
        });

        // CHECK: согласие обязательно (consent = true) — жёсткий инвариант на уровне БД.
        DB::statement('ALTER TABLE leads ADD CONSTRAINT chk_leads_consent CHECK (consent = true)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS chk_leads_consent');

        Schema::dropIfExists('leads');
    }
};
