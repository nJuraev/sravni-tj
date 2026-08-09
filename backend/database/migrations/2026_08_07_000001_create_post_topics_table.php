<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пул generic-тем для ежедневных финансовых постов в Telegram-канал
 * (см. RunFinancePostsScheduler + PostTopicSelector).
 *
 * last_used_at управляет LRU-выбором: тема с самым старым/null значением
 * выбирается следующей, поэтому при активном пуле из N тем интервал повтора
 * гарантированно = N дней. Новая тема (last_used_at = NULL) автоматически
 * попадает в приоритетную группу — это и есть механизм «подкинуть тему».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_topics', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('title', 255);
            $table->text('prompt');

            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_used_at')->nullable();

            $table->timestampsTz();
        });

        Schema::table('post_topics', function (Blueprint $table) {
            $table->index(['is_active', 'last_used_at'], 'idx_post_topics_selection');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_topics');
    }
};
