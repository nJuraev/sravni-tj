<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Правило получения tj-версии URL из ru-версии — на уровне банка (не продукта).
 *
 * Часть банков раздаёт язык через параметр/путь на ВЕСЬ сайт одним и тем же
 * способом (напр. SSB: ?language_id=2 ru / =1 tj). Зная только ru-URL продукта
 * (из discovery или прямого сидинга), парсер сам выводит tj-URL по этому
 * правилу и скрейпит обе версии для одного продукта — без ручного дублирования
 * пары URL на каждый продукт.
 *
 * lang_url_rule_type:
 *   - query_param  → params {"param":"language_id","ru":"2","tj":"1"}
 *   - path_replace → params {"ru":"/ru/","tj":"/tj/"}
 *   - header       → params {"header":"Accept-Language","ru":"ru","tj":"tj"}.
 *                     URL ОДИН И ТОТ ЖЕ для обоих языков — различает HTTP-
 *                     заголовок запроса (напр. ICB: JSON API, язык только по
 *                     Accept-Language). Идёт НЕ через Scraper (Jina/Firecrawl),
 *                     а прямым HTTP GET — см. parser.go fetchHeaderBilingual.
 *   - NULL         → сайт одноязычный либо оба языка на одной странице; вторую
 *                     версию не ищем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->string('lang_url_rule_type', 20)->nullable()->after('logo_url');
            $table->jsonb('lang_url_rule_params')->nullable()->after('lang_url_rule_type');
        });

        DB::statement("ALTER TABLE banks ADD CONSTRAINT chk_banks_lang_url_rule_type CHECK (lang_url_rule_type IS NULL OR lang_url_rule_type IN ('query_param','path_replace','header'))");
        DB::statement('ALTER TABLE banks ADD CONSTRAINT chk_banks_lang_url_rule_params CHECK (lang_url_rule_type IS NULL OR lang_url_rule_params IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE banks DROP CONSTRAINT IF EXISTS chk_banks_lang_url_rule_params');
        DB::statement('ALTER TABLE banks DROP CONSTRAINT IF EXISTS chk_banks_lang_url_rule_type');

        Schema::table('banks', function (Blueprint $table) {
            $table->dropColumn(['lang_url_rule_type', 'lang_url_rule_params']);
        });
    }
};
