<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\FinancePost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancePost
 */
class AdminFinancePostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subjectLabel = match ($this->kind) {
            'generic' => $this->topic?->title,
            'product' => $this->subjectProduct()?->name_ru,
            'currency' => 'Курсы валют',
            'news' => 'По новости',
            default => null,
        };

        return [
            'id' => (int) $this->id,
            'kind' => $this->kind,
            'subject_label' => $subjectLabel,
            'body' => $this->body,
            'status' => $this->status,
            'generated_at' => optional($this->generated_at)->toIso8601ZuluString(),
            'send_at' => optional($this->send_at)->toIso8601ZuluString(),
            'sent_at' => optional($this->sent_at)->toIso8601ZuluString(),
            'error' => $this->error,
        ];
    }
}
