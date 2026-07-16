<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rate_rule — конфиг детерминированного (без AI) извлечения курсов валют
 * для kind='rates'. NULL = старый путь (scrape → AI) без изменений —
 * обратная совместимость для банков, ещё не переведённых на детерминированный
 * разбор.
 *
 * Формат (format=json_path): start_url отдаётся как ЧИСТЫЙ JSON (прямой
 * HTTP GET, БЕЗ Jina/Firecrawl и БЕЗ AI), значения читаются по путям
 * (см. parser/internal/rates/deterministic.go resolvePath):
 * {
 *   "format": "json_path",
 *   "items": [
 *     {"currency":"USD","category":"cash","buy_path":"USD_buy","sell_path":"USD_sell"}
 *   ]
 * }
 *
 * Путь — точка разделяет сегменты; у сегмента опционально один и более
 * [...]-селекторов: число → индекс массива, "field=value[,field2=value2]" —
 * первый элемент массива, где ВСЕ условия выполняются (field — имя ключа
 * объекта ИЛИ числовой индекс внутри элемента-массива, для позиционных
 * массивов типа [["USD","9.2","9.3"],...]). Примеры реальных путей —
 * см. rate_rule банков ssb/icb/freedom (объекты) и arvand/dbt/tawhidbank
 * (массивы) в BankParseInstructionSeeder.
 *
 * Курсы — числа, показываемые пользователю напрямую: детерминированный путь
 * предпочтительнее AI (ниже риск галлюцинации, дешевле при частом (ежечасном)
 * прогоне). Не все банки переводимы (нужен фетчащийся напрямую JSON-эндпоинт,
 * не встроенный в HTML/<script>) — такие остаются на AI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_parse_instructions', function (Blueprint $table) {
            $table->jsonb('rate_rule')->nullable()->after('css_hints');
        });
    }

    public function down(): void
    {
        Schema::table('bank_parse_instructions', function (Blueprint $table) {
            $table->dropColumn('rate_rule');
        });
    }
};
