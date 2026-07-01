<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Подкатегория продукта (для credit/deposit). Заполняется парсером (AI-классификация).
 * credit: consumer|mortgage|auto|business|agro|education|refinance|pawn
 * deposit: term|savings|demand|kids
 * общий fallback: other; для installment — NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('subcategory', 20)->nullable()->after('category');
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT chk_products_subcategory CHECK (subcategory IS NULL OR subcategory IN ('consumer','mortgage','auto','business','agro','education','refinance','pawn','term','savings','demand','kids','other'))");

        // Фильтр по подкатегории в каталоге.
        Schema::table('products', function (Blueprint $table) {
            $table->index('subcategory', 'idx_products_subcategory');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_subcategory');
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_subcategory');
            $table->dropColumn('subcategory');
        });
    }
};
