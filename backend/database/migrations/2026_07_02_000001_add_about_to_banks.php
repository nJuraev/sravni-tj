<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * banks.about_ru/about_tg — короткая редакторская справка о банке для
 * публичной страницы банка (/bank/:id). Заполняется через админку, парсер
 * это поле не трогает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->text('about_ru')->nullable()->after('address_tg');
            $table->text('about_tg')->nullable()->after('about_ru');
        });
    }

    public function down(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->dropColumn(['about_ru', 'about_tg']);
        });
    }
};
