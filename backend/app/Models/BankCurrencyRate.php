<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Курс валюты банка. Пишется только парсером курсов (cmd/rates).
 * Одна строка = банк × валюта × категория (cash/transfer) × дата,
 * с парой buy/sell (любая сторона может быть null, если банк её не котирует).
 *
 * @property int $id
 * @property int $bank_id
 * @property string $currency
 * @property string $category
 * @property string|null $buy
 * @property string|null $sell
 * @property \Illuminate\Support\Carbon $rate_date
 * @property \Illuminate\Support\Carbon|null $parsed_at
 */
class BankCurrencyRate extends Model
{
    /** @use HasFactory<\Database\Factories\BankCurrencyRateFactory> */
    use HasFactory;

    protected $table = 'bank_currency_rates';

    /** Backend не пишет курсы (это парсер). */
    protected $guarded = ['*'];

    protected $casts = [
        'buy' => 'decimal:4',
        'sell' => 'decimal:4',
        'rate_date' => 'date',
        'parsed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }
}
