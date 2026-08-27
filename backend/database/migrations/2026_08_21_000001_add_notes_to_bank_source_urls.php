<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bank_source_urls.notes — курируемая подсказка AI-экстрактору ДЛЯ ЭТОЙ
 * КОНКРЕТНОЙ страницы продукта (parser/internal/parser, обычный путь
 * scrape → extract, НЕ discovery — там подсказки уже есть в
 * bank_parse_instructions.notes).
 *
 * Нужна для страниц вроде amonatbonk.tj/ru/personal/hypothec/: это ОДНА
 * страница ОДНОГО продукта (ипотека), но условия (ставка/срок) на ней
 * разбиты на несколько карточек-вариантов по сроку кредита — без подсказки
 * AI может ошибочно счесть карточки отдельными продуктами или уйти в
 * catalog-режим (product_links) вместо извлечения rate_tiers с одной
 * страницы. Заполняется вручную по мере находок, как и
 * bank_parse_instructions.notes — не автогенерируется.
 *
 * nullable — подавляющее большинство страниц не нуждаются ни в какой
 * подсказке, общего system-prompt достаточно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('scraper');
        });
    }

    public function down(): void
    {
        Schema::table('bank_source_urls', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
