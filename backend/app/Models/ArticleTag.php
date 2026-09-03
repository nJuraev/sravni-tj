<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Тег статьи. Создаётся редактором на лету из admin UI (нет CHECK-enum).
 *
 * @property int $id
 * @property string $name_ru
 * @property string|null $name_tg
 * @property string $slug
 */
class ArticleTag extends Model
{
    protected $table = 'article_tags';

    protected $fillable = [
        'name_ru',
        'name_tg',
        'slug',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsToMany<Article, $this> */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_tag', 'tag_id', 'article_id');
    }
}
