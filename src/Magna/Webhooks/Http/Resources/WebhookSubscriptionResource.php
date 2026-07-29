<?php

declare(strict_types=1);

namespace Magna\Webhooks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Magna\Webhooks\WebhookSubscription;

/**
 * @mixin WebhookSubscription
 */
class WebhookSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'secret' => $this->secret,
            'events' => $this->events,
            'active' => $this->active,
            'description' => $this->description,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
