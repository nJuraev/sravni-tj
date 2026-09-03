<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_sent_at — когда статья была отправлена в TG-группу (ручной триггер
 * из админки, см. SendArticleToTelegramJob). NULL = ещё не отправлялась.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->timestampTz('telegram_sent_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('telegram_sent_at');
        });
    }
};
