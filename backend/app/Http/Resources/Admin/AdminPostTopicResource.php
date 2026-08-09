<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PostTopic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PostTopic
 */
class AdminPostTopicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'prompt' => $this->prompt,
            'is_active' => (bool) $this->is_active,
            'last_used_at' => optional($this->last_used_at)->toIso8601ZuluString(),
            'created_at' => optional($this->created_at)->toIso8601ZuluString(),
            'updated_at' => optional($this->updated_at)->toIso8601ZuluString(),
        ];
    }
}
