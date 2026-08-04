<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RateAlertSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RateAlertSubscription
 */
class RateAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'category' => $this->category,
            'currency' => $this->currency,
            'op' => $this->op,
            'direction' => $this->direction,
            'threshold' => (float) $this->threshold,
            'last_notified_value' => $this->last_notified_value !== null ? (float) $this->last_notified_value : null,
            'last_notified_at' => optional($this->last_notified_at)->toIso8601ZuluString(),
            'created_at' => optional($this->created_at)->toIso8601ZuluString(),
        ];
    }
}
