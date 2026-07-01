<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * URL-источник для парсинга (один банк → много URL по категориям).
 *
 * @property int $id
 * @property int $bank_id
 * @property string $category
 * @property string $url
 * @property string|null $email
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_parsed_at
 */
class BankSourceUrl extends Model
{
    /** @use HasFactory<\Database\Factories\BankSourceUrlFactory> */
    use HasFactory;

    protected $table = 'bank_source_urls';

    /** Backend не пишет в источники — заполняет парсер/администратор. */
    protected $guarded = ['*'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_parsed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'source_url_id');
    }

    /** @return HasMany<ParserRun, $this> */
    public function parserRuns(): HasMany
    {
        return $this->hasMany(ParserRun::class, 'bank_source_url_id');
    }
}
