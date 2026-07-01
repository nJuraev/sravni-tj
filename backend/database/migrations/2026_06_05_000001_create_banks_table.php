<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица banks — банки-источники.
 *
 * Enum-поля (status) реализованы как VARCHAR + CHECK-ограничение,
 * а не нативный Postgres ENUM: добавление нового значения в CHECK
 * не требует ALTER TYPE и блокировок (см. schema.md §0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Мультиязычная пара названий: хотя бы одно из ru/tg обязательно (CHECK ниже).
            $table->string('name_ru', 255);
            $table->string('name_tg', 255)->nullable();

            // Машинно-читаемый идентификатор банка (eskhata, alif) — уникальный.
            $table->string('slug', 120)->unique('uq_banks_slug');

            // Справочный (публичный) email банка из реестра НБТ — НЕ для доставки заявок.
            // Адрес доставки лида живёт на bank_source_urls.email (по категории).
            $table->string('contact_email', 255)->nullable();

            // Главный сайт банка (корень для парсинга и ссылка в карточке).
            $table->string('website', 500)->nullable();

            // Контакты из реестра НБТ — для карточки банка.
            $table->string('phone', 150)->nullable();
            $table->string('address_ru', 500)->nullable();
            $table->string('address_tg', 500)->nullable();

            // Статус банка: active|inactive (VARCHAR + CHECK).
            $table->string('status', 16)->default('active');

            // Опциональный признак партнёрства.
            $table->boolean('is_partner')->default(false);

            // Опционально для витрины.
            $table->string('logo_url', 500)->nullable();

            // TIMESTAMPTZ с DEFAULT now() (useCurrent на обеих метках).
            $table->timestampsTz();
        });

        // Фильтр «только активные банки» в API.
        Schema::table('banks', function (Blueprint $table) {
            $table->index('status', 'idx_banks_status');
        });

        // CHECK-ограничения (enum + бизнес-инварианты) — добавляем сырым SQL,
        // т.к. Laravel Schema Builder не выражает CHECK напрямую.
        DB::statement("ALTER TABLE banks ADD CONSTRAINT chk_banks_status CHECK (status IN ('active','inactive'))");
        DB::statement('ALTER TABLE banks ADD CONSTRAINT chk_banks_name_present CHECK (name_ru IS NOT NULL OR name_tg IS NOT NULL)');
        // Проверка формата справочного email (nullable): NULL допустим.
        DB::statement("ALTER TABLE banks ADD CONSTRAINT chk_banks_contact_email_format CHECK (contact_email IS NULL OR contact_email ~* '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$')");
    }

    public function down(): void
    {
        // Снимаем CHECK перед удалением таблицы (на случай частичного отката/диагностики).
        DB::statement('ALTER TABLE banks DROP CONSTRAINT IF EXISTS chk_banks_status');
        DB::statement('ALTER TABLE banks DROP CONSTRAINT IF EXISTS chk_banks_name_present');
        DB::statement('ALTER TABLE banks DROP CONSTRAINT IF EXISTS chk_banks_contact_email_format');

        Schema::dropIfExists('banks');
    }
};
