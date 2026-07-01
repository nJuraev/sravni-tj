<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица parser_runs — метаданные запусков парсера.
 *
 * Пишется только при PARSER_DEBUG_LOG=true. Лог переживает удаление источника
 * (bank_source_url_id ON DELETE SET NULL). Хранит сырой ответ AI для отладки
 * галлюцинаций, статус, тайминги и ошибки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_runs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // FK → bank_source_urls(id) ON DELETE SET NULL (лог переживает источник).
            $table->foreignId('bank_source_url_id')
                ->nullable()
                ->constrained('bank_source_urls')
                ->nullOnDelete();

            // Тайминги запуска.
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();

            // Статус запуска: success|error|partial (VARCHAR + CHECK).
            $table->string('status', 16);

            // Сырой ответ AI (для отладки галлюцинаций) и текст ошибки при сбое.
            $table->text('ai_raw_response')->nullable();
            $table->text('error_message')->nullable();

            // Сколько продуктов записано в запуске.
            $table->integer('products_upserted')->nullable()->default(0);
        });

        Schema::table('parser_runs', function (Blueprint $table) {
            $table->index('bank_source_url_id', 'idx_runs_source');
            $table->index('started_at', 'idx_runs_started');
            // Быстро найти упавшие запуски.
            $table->index('status', 'idx_runs_status');
        });

        // CHECK: enum статуса запуска.
        DB::statement("ALTER TABLE parser_runs ADD CONSTRAINT chk_runs_status CHECK (status IN ('success','error','partial'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE parser_runs DROP CONSTRAINT IF EXISTS chk_runs_status');

        Schema::dropIfExists('parser_runs');
    }
};
