<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица article_categories — категории статей блога.
 *
 * Управляются через админку (не CHECK-enum, как products.category), т.к.
 * список должен расширяться редактором без деплоя. is_active скрывает
 * категорию из публичных фильтров, не удаляя (у неё могут быть статьи).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_categories', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('name_ru', 100);
            $table->string('name_tg', 100)->nullable();

            $table->string('slug', 120)->unique('uq_article_categories_slug');

            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_categories');
    }
};
