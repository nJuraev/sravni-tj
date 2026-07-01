<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заявка. Единственная таблица, в которую пишет backend.
 *
 * @property int $id
 * @property int|null $product_id
 * @property int $bank_id
 * @property string $full_name
 * @property string $phone
 * @property bool $consent
 * @property \Illuminate\Support\Carbon $created_at
 */
class Lead extends Model
{
    protected $table = 'leads';

    /**
     * bank_id задаётся СЕРВЕРОМ по product_id (не из клиентского ввода),
     * поэтому он тоже в fillable — заполняем его в контроллере явно,
     * а не из request-данных.
     */
    protected $fillable = [
        'product_id',
        'bank_id',
        'full_name',
        'phone',
        'consent',
    ];

    /** Таблица leads имеет только created_at. */
    public const UPDATED_AT = null;

    protected $casts = [
        'consent' => 'boolean',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }
}
