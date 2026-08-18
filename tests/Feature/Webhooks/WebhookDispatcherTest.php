<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Magna\Webhooks\Jobs\DispatchWebhookJob;
use Magna\Webhooks\WebhookDelivery;
use Magna\Webhooks\WebhookDispatcher;
use Magna\Webhooks\WebhookSubscription;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function webhookSubscription(array $events, bool $active = true): WebhookSubscription
{
    return WebhookSubscription::create([
        'url' => 'https://example.com/hook',
        'secret' => 'shhh',
        'events' => $events,
        'active' => $active,
        'description' => null,
    ]);
}

it('records a delivery and queues the job for a matching active subscription', function () {
    Queue::fake();
    $subscription = webhookSubscription(['defence.attack_detected']);

    app(WebhookDispatcher::class)->fire('defence.attack_detected', ['type' => 'login.attack_detected']);

    $delivery = WebhookDelivery::query()->where('subscription_id', $subscription->id)->first();

    expect($delivery)->not->toBeNull();
    expect($delivery->event)->toBe('defence.attack_detected');
    expect($delivery->payload['type'])->toBe('login.attack_detected');
    expect($delivery->payload['event'])->toBe('defence.attack_detected');
    Queue::assertPushed(DispatchWebhookJob::class);
});

it('skips a subscription that does not want the event', function () {
    Queue::fake();
    webhookSubscription(['entry.created']);

    app(WebhookDispatcher::class)->fire('defence.attack_detected', []);

    expect(WebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('skips inactive subscriptions', function () {
    Queue::fake();
    webhookSubscription(['defence.attack_detected'], active: false);

    app(WebhookDispatcher::class)->fire('defence.attack_detected', []);

    expect(WebhookDelivery::query()->count())->toBe(0);
});
