<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Колонка input_markdown — markdown страницы, отправленный в AI (вход).
 * Пишется только при PARSER_DEBUG_LOG=true (как и весь parser_runs).
 * Нужна для отладки: видеть, ЧТО именно ушло в модель, а не только её ответ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_runs', function (Blueprint $table) {
            $table->text('input_markdown')->nullable()->after('ai_raw_response');
        });
    }

    public function down(): void
    {
        Schema::table('parser_runs', function (Blueprint $table) {
            $table->dropColumn('input_markdown');
        });
    }
};
