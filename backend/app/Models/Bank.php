<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Банк-источник.
 *
 * @property int $id
 * @property string $name_ru
 * @property string|null $name_tg
 * @property string $slug
 * @property string|null $contact_email
 * @property string|null $website
 * @property string|null $phone
 * @property string|null $address_ru
 * @property string|null $address_tg
 * @property string|null $about_ru
 * @property string|null $about_tg
 * @property string $status
 * @property bool $is_partner
 * @property string|null $logo_url
 */
class Bank extends Model
{
    /** @use HasFactory<\Database\Factories\BankFactory> */
    use HasFactory;

    protected $table = 'banks';

    /**
     * Каталог backend НИКОГДА не пишет в banks (это парсер/администратор).
     * Поэтому массовое заполнение полностью запрещаем.
     */
    protected $guarded = ['*'];

    protected $casts = [
        'is_partner' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return HasMany<BankSourceUrl, $this> */
    public function sourceUrls(): HasMany
    {
        return $this->hasMany(BankSourceUrl::class, 'bank_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'bank_id');
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'bank_id');
    }

    /** @return HasMany<BankReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(BankReview::class, 'bank_id');
    }

    /**
     * Только активные банки (status = 'active').
     *
     * @param  Builder<Bank>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /**
     * Подгружает агрегаты рейтинга по ОДОБРЕННЫМ отзывам:
     *  - reviews_count — число одобренных отзывов;
     *  - rating_avg — средний балл (null, если отзывов нет).
     * Используется в каталоге и списке банков (без N+1, без денормализации).
     *
     * @param  Builder<Bank>  $query
     */
    public function scopeWithReviewStats(Builder $query): void
    {
        $query
            ->withCount(['reviews as reviews_count' => fn (Builder $r) => $r->where('status', 'approved')])
            ->withAvg(['reviews as rating_avg' => fn (Builder $r) => $r->where('status', 'approved')], 'rating');
    }
}
