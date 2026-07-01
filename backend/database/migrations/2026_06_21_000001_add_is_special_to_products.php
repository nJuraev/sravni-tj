<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флаг «особый» продукт. «Аномальные» кредиты (легализация средств и пр.),
 * которые обычному клиенту не нужны и портят сортировку по ставке (большой
 * разброс), помечаются is_special=true и по умолчанию СКРЫТЫ из выдачи.
 * Показываются только по явному запросу (?special=true — «галочка особые»).
 *
 * Проставляется прямым UPDATE в БД (модерация на MVP без админки); позже
 * парсер сможет классифицировать автоматически.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_special')->default(false)->after('subcategory');

            // Каталог по умолчанию фильтрует is_special=false — частичный индекс
            // под «обычную» выдачу (особых мало, основной поток — обычные).
            $table->index(['category', 'is_special'], 'idx_products_special');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_special');
            $table->dropColumn('is_special');
        });
    }
};
