<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Статья блога. Авторский контент (владелец/жена через admin UI) — модель
 * пишет через $fillable, не forceFill (см. FinancePost/PostTopic, не
 * Product/Bank, которые управляются парсером).
 *
 * @property int $id
 * @property string $title_ru
 * @property string|null $title_tg
 * @property string $slug
 * @property string|null $excerpt_ru
 * @property string|null $excerpt_tg
 * @property string $body_ru
 * @property string|null $body_tg
 * @property string|null $cover_image
 * @property string|null $youtube_url
 * @property int $category_id
 * @property string $status
 * @property Carbon|null $published_at
 * @property string|null $author_name
 * @property int|null $related_bank_id
 * @property int|null $related_product_id
 * @property Carbon|null $telegram_sent_at
 */
class Article extends Model
{
    protected $table = 'articles';

    protected $fillable = [
        'title_ru',
        'title_tg',
        'slug',
        'excerpt_ru',
        'excerpt_tg',
        'body_ru',
        'body_tg',
        'cover_image',
        'youtube_url',
        'category_id',
        'status',
        'published_at',
        'author_name',
        'related_bank_id',
        'related_product_id',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'telegram_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<ArticleCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }

    /** @return BelongsToMany<ArticleTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ArticleTag::class, 'article_tag', 'article_id', 'tag_id');
    }

    /** @return BelongsTo<Bank, $this> */
    public function relatedBank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'related_bank_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }

    /**
     * Публично видна только опубликованная статья с наступившей датой публикации
     * (аналог Product::scopeVisible).
     *
     * @param  Builder<Article>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', 'published')
            ->where('published_at', '<=', now());
    }
}
