<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Категория статей блога. Управляется через админку — не CHECK-enum, чтобы
 * редактор мог добавлять новые категории без деплоя.
 *
 * @property int $id
 * @property string $name_ru
 * @property string|null $name_tg
 * @property string $slug
 * @property bool $is_active
 */
class ArticleCategory extends Model
{
    protected $table = 'article_categories';

    protected $fillable = [
        'name_ru',
        'name_tg',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return HasMany<Article, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id');
    }

    /** @param Builder<ArticleCategory> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
