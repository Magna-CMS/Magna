<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Webhooks\Jobs\DispatchWebhookJob;
use Magna\Webhooks\Support\WebhookSender;
use Magna\Webhooks\Support\WebhookSigner;
use Magna\Webhooks\Support\WebhookUrlBlockedException;
use Magna\Webhooks\Support\WebhookUrlGuard;
use Magna\Webhooks\WebhookDelivery;
use Magna\Webhooks\WebhookSubscription;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The guard's own docblock names the threat: a webhook URL is fully
 * attacker-controlled, the delivery job POSTs to it with the app's network
 * access, and the response body is stored and readable — a non-blind SSRF
 * oracle against cloud metadata and internal services. These tests pin the
 * refusals with literal addresses (no DNS involved), the fail-closed
 * behaviour on an unresolvable host, and the pinned-IP contract
 * WebhookSender feeds to CURLOPT_RESOLVE.
 */

it('refuses a URL that resolves to a non-routable address', function (string $url): void {
    expect(fn () => WebhookUrlGuard::ensureSafe($url))
        ->toThrow(WebhookUrlBlockedException::class);
})->with([
    'cloud metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'],
    'loopback with service port' => ['http://127.0.0.1:6379/'],
    'RFC1918 10/8' => ['http://10.0.0.1/hook'],
    'RFC1918 172.16/12' => ['https://172.16.0.1/hook'],
    'RFC1918 192.168/16' => ['https://192.168.1.10/hook'],
    'this-network 0/8' => ['http://0.0.0.0/'],
    'IPv6 loopback' => ['http://[::1]/hook'],
    'IPv6 unique-local' => ['http://[fd00::1]/hook'],
]);

it('refuses a scheme that is not http or https', function (string $url): void {
    expect(fn () => WebhookUrlGuard::ensureSafe($url))
        ->toThrow(WebhookUrlBlockedException::class, 'must use http or https');
})->with([
    'ftp' => ['ftp://example.com/x'],
    'file' => ['file:///etc/passwd'],
    'gopher' => ['gopher://example.com/'],
    'no scheme at all' => ['example.com/hook'],
]);

it('fails closed on a host that cannot be resolved', function (): void {
    // .invalid is reserved (RFC 2606) and never resolves — an unresolvable
    // or unstable host must be refused, not waved through unchecked.
    expect(fn () => WebhookUrlGuard::ensureSafe('https://webhook-target.invalid/hook'))
        ->toThrow(WebhookUrlBlockedException::class, 'could not be resolved');
});

it('returns the pinned target the sender must connect to', function (): void {
    // A literal public IP resolves to itself, so the pin is deterministic:
    // the ip handed to CURLOPT_RESOLVE is exactly the address that passed
    // the range checks, with the scheme default port filled in.
    expect(WebhookUrlGuard::resolvePinnedTarget('https://203.0.113.7/hook'))
        ->toBe(['host' => '203.0.113.7', 'port' => 443, 'ip' => '203.0.113.7'])
        ->and(WebhookUrlGuard::resolvePinnedTarget('http://203.0.113.7/hook'))
        ->toBe(['host' => '203.0.113.7', 'port' => 80, 'ip' => '203.0.113.7'])
        ->and(WebhookUrlGuard::resolvePinnedTarget('http://203.0.113.7:8080/hook')['port'])
        ->toBe(8080);
});

it('reports isSafe() without throwing', function (): void {
    expect(WebhookUrlGuard::isSafe('http://169.254.169.254/'))->toBeFalse()
        ->and(WebhookUrlGuard::isSafe('https://203.0.113.7/hook'))->toBeTrue();
});

it('signs a payload a consumer can verify, timestamp bound', function (): void {
    $signature = (new WebhookSigner)->sign('{"a":1}', 'topsecret', 1_700_000_000);

    // The documented scheme: HMAC-SHA256("{timestamp}.{body}", secret) —
    // what a consumer recomputes to verify authenticity and replay window.
    expect($signature)->toBe('sha256='.hash_hmac('sha256', '1700000000.{"a":1}', 'topsecret'))
        ->and((new WebhookSigner)->sign('{"a":2}', 'topsecret', 1_700_000_000))
        ->not->toBe($signature);
});

/*
 * The docblock's second demand: the guard runs again immediately before
 * every dispatch, not only when the subscription was saved — DNS can be
 * rebound in between. A URL that is hostile by the time the job fires must
 * kill the delivery, never reach the network.
 */
it('re-checks the URL at dispatch time and kills a hostile delivery', function (): void {
    $subscription = WebhookSubscription::create([
        'url' => 'http://169.254.169.254/latest/meta-data/',
        'secret' => 'shhh',
        'events' => ['entry.created'],
        'active' => true,
        'description' => null,
    ]);

    $delivery = WebhookDelivery::create([
        'subscription_id' => $subscription->id,
        'event' => 'entry.created',
        'payload' => ['event' => 'entry.created'],
        'status' => 'pending',
        'attempts' => 0,
    ]);

    (new DispatchWebhookJob($delivery->id))->handle(
        app(WebhookSigner::class),
        app(WebhookSender::class),
    );

    $delivery->refresh();

    expect($delivery->status)->toBe('dead')
        ->and((string) $delivery->response_body)->toStartWith('Blocked:');
});
