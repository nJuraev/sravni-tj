<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Generic-тема для ежедневных постов в Telegram-канал. last_used_at
 * управляет LRU-выбором в PostTopicSelector.
 *
 * @property int $id
 * @property string $title
 * @property string $prompt
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class PostTopic extends Model
{
    protected $table = 'post_topics';

    protected $fillable = [
        'title',
        'prompt',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return HasMany<FinancePost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(FinancePost::class, 'post_topic_id');
    }

    /**
     * @param  Builder<PostTopic>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
