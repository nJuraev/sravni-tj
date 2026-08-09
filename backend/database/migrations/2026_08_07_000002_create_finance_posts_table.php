<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сгенерированные посты для Telegram-канала (RunFinancePostsScheduler).
 *
 * kind различает, из чего построен контекст промпта:
 *  - generic — тема из post_topics (post_topic_id заполнен);
 *  - product — конкретный продукт из products (subject_type='product', subject_id=products.id);
 *  - currency — снепшот bank_currency_rates на момент генерации (без subject).
 *
 * send_at — момент фактической отправки (generated_at + random 1..90 мин),
 * вычисляется в момент генерации и передаётся в delay() у SendFinancePostJob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_posts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('kind', 16);

            $table->foreignId('post_topic_id')
                ->nullable()
                ->constrained('post_topics')
                ->nullOnDelete();

            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->text('body');
            $table->string('status', 16)->default('pending');

            $table->timestampTz('generated_at');
            $table->timestampTz('send_at');
            $table->timestampTz('sent_at')->nullable();

            $table->bigInteger('telegram_message_id')->nullable();
            $table->text('error')->nullable();

            $table->timestampsTz();
        });

        Schema::table('finance_posts', function (Blueprint $table) {
            $table->index('send_at', 'idx_finance_posts_send_at');
            $table->index(['subject_type', 'subject_id'], 'idx_finance_posts_subject');
            $table->index('status', 'idx_finance_posts_status');
        });

        DB::statement("ALTER TABLE finance_posts ADD CONSTRAINT chk_finance_posts_kind CHECK (kind IN ('generic','product','currency'))");
        DB::statement("ALTER TABLE finance_posts ADD CONSTRAINT chk_finance_posts_status CHECK (status IN ('pending','sent','failed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE finance_posts DROP CONSTRAINT IF EXISTS chk_finance_posts_kind');
        DB::statement('ALTER TABLE finance_posts DROP CONSTRAINT IF EXISTS chk_finance_posts_status');

        Schema::dropIfExists('finance_posts');
    }
};
