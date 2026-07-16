<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * array_path — включает array-split режим парсера (parser/internal/parser
 * arraysplit.go): вместо ОДНОГО AI-вызова на всю страницу, ответ источника
 * разбирается как JSON, jsonpath.Resolve(data, array_path) достаёт МАССИВ
 * продуктов, и на КАЖДЫЙ элемент — отдельный маленький AI-вызов.
 *
 * Нужен для источников, где вся страница — это JSON-массив продуктов целиком
 * (SSB/ICB/Арванд): раньше весь массив (10+ продуктов, оба языка) шёл в AI
 * ОДНИМ вызовом — на больших каталогах упирались в потолок вывода модели
 * (DeepSeek: 8192 токенов, ответ обрывался на середине JSON).
 *
 * NULL (по умолчанию) — режим выключен, старое поведение (один вызов на
 * страницу либо index-режим по ссылкам AI из product_links) не меняется.
 *
 * Значение — путь (см. jsonpath.Resolve): "results" (SSB — массив под ключом
 * results), "data" (ICB — Laravel-пагинация {"data":[...],"links":...}),
 * "" (Арванд — сам ответ уже массив, без обёртки).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->string('array_path', 100)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->dropColumn('array_path');
        });
    }
};
