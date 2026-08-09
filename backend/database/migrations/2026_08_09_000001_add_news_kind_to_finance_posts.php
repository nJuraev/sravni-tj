<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Добавляет kind='news' — внеплановый пост по внешнему источнику (Admin
 * вставляет текст новости, LLM пересказывает своими словами), не привязан
 * к недельному паттерну PostTopicSelector::WEEKLY_PATTERN.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE finance_posts DROP CONSTRAINT IF EXISTS chk_finance_posts_kind');
        DB::statement("ALTER TABLE finance_posts ADD CONSTRAINT chk_finance_posts_kind CHECK (kind IN ('generic','product','currency','news'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE finance_posts DROP CONSTRAINT IF EXISTS chk_finance_posts_kind');
        DB::statement("ALTER TABLE finance_posts ADD CONSTRAINT chk_finance_posts_kind CHECK (kind IN ('generic','product','currency'))");
    }
};
