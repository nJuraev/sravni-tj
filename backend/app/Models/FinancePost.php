<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сгенерированный пост для Telegram-канала. kind различает источник контекста
 * (generic — тема из post_topics, product — subject_id указывает на products.id,
 * currency — снепшот курсов на момент генерации, без subject).
 *
 * @property int $id
 * @property string $kind
 * @property int|null $post_topic_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $body
 * @property string $status
 * @property \Illuminate\Support\Carbon $generated_at
 * @property \Illuminate\Support\Carbon $send_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property int|null $telegram_message_id
 * @property string|null $error
 */
class FinancePost extends Model
{
    protected $table = 'finance_posts';

    protected $fillable = [
        'kind',
        'post_topic_id',
        'subject_type',
        'subject_id',
        'body',
        'status',
        'generated_at',
        'send_at',
        'sent_at',
        'telegram_message_id',
        'error',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
        'telegram_message_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<PostTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(PostTopic::class, 'post_topic_id');
    }

    /**
     * Продукт-предмет поста, если kind='product'. Не Eloquent-relation
     * (subject_id — полиморфный по смыслу, но пока единственный тип 'product'),
     * поэтому явный метод вместо morphTo на один тип.
     */
    public function subjectProduct(): ?Product
    {
        if ($this->subject_type !== 'product' || $this->subject_id === null) {
            return null;
        }

        return Product::find($this->subject_id);
    }
}
