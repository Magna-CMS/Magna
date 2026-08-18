<?php

declare(strict_types=1);

namespace Magna\Webhooks;

use Illuminate\Database\Eloquent\Collection;
use Magna\Webhooks\Jobs\DispatchWebhookJob;

/**
 * Fires a webhook event by key: matches every active subscription that wants it,
 * records a delivery, and queues the send.
 *
 * The one public entry point for emitting a webhook — core's own
 * WebhookEventSubscriber uses it for content/media events, and any plugin that
 * declares event keys (RegistersWebhookEvents) can fire them through it without
 * reaching into the delivery models itself.
 */
class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function fire(string $eventKey, array $data): void
    {
        /** @var Collection<int, WebhookSubscription> $subscriptions */
        $subscriptions = WebhookSubscription::query()->where('active', true)->get();

        foreach ($subscriptions as $subscription) {
            if (! $subscription->subscribesTo($eventKey)) {
                continue;
            }

            $delivery = WebhookDelivery::create([
                'subscription_id' => $subscription->id,
                'event' => $eventKey,
                'payload' => array_merge($data, [
                    'event' => $eventKey,
                    'timestamp' => now()->toIso8601String(),
                ]),
                'status' => 'pending',
                'attempts' => 0,
            ]);

            DispatchWebhookJob::dispatch($delivery->id);
        }
    }
}
