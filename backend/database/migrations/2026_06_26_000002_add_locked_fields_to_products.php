<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * products.locked_fields — список полей, заданных через админку, которые
 * парсер НЕ должен перезаписывать (jsonb-массив имён, например
 * ["category","subcategory","features"]).
 *
 * Приоритет администратора над парсером: при upsert парсер проверяет
 * вхождение поля в locked_fields и сохраняет значение из БД; метки (features)
 * всегда объединяются (union), новые добавляются, существующие не теряются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->jsonb('locked_fields')->default(DB::raw("'[]'::jsonb"))->after('features');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('locked_fields');
        });
    }
};
