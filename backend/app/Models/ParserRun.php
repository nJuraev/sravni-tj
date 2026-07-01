<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Метаданные запуска парсера (пишется только при PARSER_DEBUG_LOG=true парсером).
 * Для backend — read-only сущность.
 *
 * @property int $id
 * @property int|null $bank_source_url_id
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property string $status
 * @property string|null $ai_raw_response
 * @property string|null $error_message
 * @property int|null $products_upserted
 */
class ParserRun extends Model
{
    protected $table = 'parser_runs';

    protected $guarded = ['*'];

    /** Таблица parser_runs не имеет updated_at (есть started_at/finished_at). */
    public $timestamps = false;

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'products_upserted' => 'integer',
    ];

    /** @return BelongsTo<BankSourceUrl, $this> */
    public function bankSourceUrl(): BelongsTo
    {
        return $this->belongsTo(BankSourceUrl::class, 'bank_source_url_id');
    }
}
