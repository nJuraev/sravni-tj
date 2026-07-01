<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка тарифной сетки: диапазон срок×сумма → ставка.
 * Валюта наследуется от products.currency (одна валюта на продукт).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $term_min
 * @property int|null $term_max
 * @property string|null $amount_min
 * @property string|null $amount_max
 * @property string $rate
 */
class ProductRate extends Model
{
    /** @use HasFactory<\Database\Factories\ProductRateFactory> */
    use HasFactory;

    protected $table = 'product_rates';

    /** Backend не пишет в тарифную сетку — пишет парсер. */
    protected $guarded = ['*'];

    /**
     * Таблица имеет только created_at (replace-all сетки, без updated_at).
     */
    public const UPDATED_AT = null;

    protected $casts = [
        'term_min' => 'integer',
        'term_max' => 'integer',
        'amount_min' => 'decimal:2',
        'amount_max' => 'decimal:2',
        'rate' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
