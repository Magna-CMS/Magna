<?php

declare(strict_types=1);

namespace Magna\Webhooks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Magna\Webhooks\WebhookDelivery;

/**
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'event' => $this->event,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'last_attempt_at' => $this->last_attempt_at?->toIso8601String(),
            'response_code' => $this->response_code,
            'response_body' => $this->response_body,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
