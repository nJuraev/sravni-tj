<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручной коэффициент приоритета банка в каталоге: выдача продуктов сортируется
 * по нему ПО УМОЛЧАНИЮ (больше — выше), пока пользователь явно не выберет
 * сортировку по ставке/сумме/сроку (см. ProductController::applySort).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->decimal('sort_coefficient', 8, 2)->default(0)->after('is_partner');
        });
    }

    public function down(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->dropColumn('sort_coefficient');
        });
    }
};
