<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица bank_source_urls — URL-источники для парсинга.
 *
 * Один банк → много URL по категориям (мультидоменность).
 * Управляются прямым UPDATE в БД без деплоя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_source_urls', function (Blueprint $table) {
            $table->bigIncrements('id');

            // FK → banks(id). Удалили банк — ушли его источники.
            $table->foreignId('bank_id')
                ->constrained('banks')
                ->cascadeOnDelete();

            // Категория источника: credit|deposit|installment (VARCHAR + CHECK).
            $table->string('category', 16);

            // Полный URL источника — заносится один раз (UNIQUE ниже).
            $table->string('url', 1000);

            // Парсер берёт только активные источники.
            $table->boolean('is_active')->default(true);

            // Адрес доставки лида ДЛЯ ЭТОЙ категории (кредит/депозит).
            // У кредита и депозита может быть свой email или общий; заполняется позже.
            $table->string('email', 255)->nullable();

            // Когда последний раз успешно распарсен.
            $table->timestampTz('last_parsed_at')->nullable();

            $table->timestampsTz();
        });

        Schema::table('bank_source_urls', function (Blueprint $table) {
            // Один URL заносится один раз.
            $table->unique('url', 'uq_bsu_url');
            // Явный индекс по FK (Laravel не создаёт его автоматически в Postgres).
            $table->index('bank_id', 'idx_bsu_bank');
        });

        // Частичный индекс под основной запрос парсера WHERE is_active = true.
        DB::statement('CREATE INDEX idx_bsu_active ON bank_source_urls (is_active) WHERE is_active = true');

        // CHECK: enum категории.
        DB::statement("ALTER TABLE bank_source_urls ADD CONSTRAINT chk_bsu_category CHECK (category IN ('credit','deposit','installment'))");
        // CHECK: формат email доставки (nullable).
        DB::statement("ALTER TABLE bank_source_urls ADD CONSTRAINT chk_bsu_email_format CHECK (email IS NULL OR email ~* '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_source_urls DROP CONSTRAINT IF EXISTS chk_bsu_email_format');
        DB::statement('ALTER TABLE bank_source_urls DROP CONSTRAINT IF EXISTS chk_bsu_category');
        DB::statement('DROP INDEX IF EXISTS idx_bsu_active');

        Schema::dropIfExists('bank_source_urls');
    }
};
