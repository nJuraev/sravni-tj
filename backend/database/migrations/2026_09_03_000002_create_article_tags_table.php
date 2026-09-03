<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица article_tags — теги статей (свободные, создаются редактором на лету
 * из admin UI, не CHECK-enum). Мультиязычная пара имён, как у категорий.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_tags', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('name_ru', 60);
            $table->string('name_tg', 60)->nullable();

            $table->string('slug', 80)->unique('uq_article_tags_slug');

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_tags');
    }
};
