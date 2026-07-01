<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отзыв о банке (премодерация: pending → approved/rejected).
 *
 * @property int $id
 * @property int $bank_id
 * @property string $author_name
 * @property int $rating
 * @property string $body
 * @property bool $consent
 * @property string $status
 */
class BankReview extends Model
{
    /** @use HasFactory<\Database\Factories\BankReviewFactory> */
    use HasFactory;

    protected $table = 'bank_reviews';

    protected $fillable = ['bank_id', 'author_name', 'rating', 'body', 'consent', 'status'];

    protected $casts = [
        'rating' => 'integer',
        'consent' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    /**
     * Только одобренные отзывы (публичная выдача + рейтинг).
     *
     * @param  Builder<BankReview>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }
}
