<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Продукт (кредит/депозит). Денормализованные агрегаты ставки/суммы/срока
 * для быстрых фильтров + детальная тарифная сетка в product_rates.
 *
 * @property int $id
 * @property int $bank_id
 * @property int|null $source_url_id
 * @property string $external_key
 * @property string $category
 * @property string|null $subcategory
 * @property bool $is_special
 * @property string|null $name_ru
 * @property string|null $name_tg
 * @property string|null $description_ru
 * @property string|null $description_tg
 * @property string $status
 * @property string $currency
 * @property string $rate_min
 * @property string $rate_max
 * @property string|null $amount_min
 * @property string|null $amount_max
 * @property int|null $term_min
 * @property int|null $term_max
 * @property array<string, bool> $features
 * @property array<int, string> $locked_fields
 * @property \Illuminate\Support\Carbon|null $parsed_at
 */
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $table = 'products';

    /** Backend никогда не пишет в products (это парсер/администратор). */
    protected $guarded = ['*'];

    protected $casts = [
        'features' => 'array',
        'locked_fields' => 'array',
        'is_special' => 'boolean',
        // Денежные/ставочные поля — decimal-строки (точная арифметика NUMERIC,
        // не float). Resource приводит их к числам для JSON по контракту.
        'rate_min' => 'decimal:3',
        'rate_max' => 'decimal:3',
        'amount_min' => 'decimal:2',
        'amount_max' => 'decimal:2',
        'term_min' => 'integer',
        'term_max' => 'integer',
        'parsed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    /** @return BelongsTo<BankSourceUrl, $this> */
    public function sourceUrl(): BelongsTo
    {
        return $this->belongsTo(BankSourceUrl::class, 'source_url_id');
    }

    /** @return HasMany<ProductRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ProductRate::class, 'product_id');
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'product_id');
    }

    /**
     * Жёсткий инвариант видимости (backend.md §3.1): продукт виден ТОЛЬКО если
     * сам active И его банк active. Применяется на уровне базового запроса,
     * чтобы исключить случайную утечку скрытых данных.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', 'active')
            ->whereHas('bank', function (Builder $bank): void {
                $bank->where('status', 'active');
            });
    }
}
