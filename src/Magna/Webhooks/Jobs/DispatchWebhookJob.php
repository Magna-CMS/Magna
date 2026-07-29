<?php

declare(strict_types=1);

namespace Magna\Webhooks\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Magna\Webhooks\Support\WebhookSender;
use Magna\Webhooks\Support\WebhookSendResult;
use Magna\Webhooks\Support\WebhookSigner;
use Magna\Webhooks\Support\WebhookUrlBlockedException;
use Magna\Webhooks\Support\WebhookUrlGuard;
use Magna\Webhooks\WebhookDelivery;
use Magna\Webhooks\WebhookSubscription;
use Throwable;

/**
 * Sends a single webhook delivery attempt.
 *
 * Up to 6 total attempts (1 initial + 5 retries) with exponential backoff.
 * Signs the payload with HMAC-SHA256 using the subscription's secret.
 * On final failure, the failed() hook marks the delivery as dead.
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Queueable;

    /** Total attempts before the job is considered permanently failed. */
    public int $tries = 6;

    public function __construct(
        private readonly string $deliveryId,
    ) {}

    public function handle(WebhookSigner $signer, WebhookSender $sender): void
    {
        $delivery = WebhookDelivery::findOrFail($this->deliveryId);
        /** @var WebhookSubscription $subscription */
        $subscription = WebhookSubscription::findOrFail($delivery->subscription_id);

        $payload = json_encode($delivery->payload);
        if ($payload === false) {
            $delivery->forceFill(['status' => 'dead'])->save();

            return;
        }

        $timestamp = Carbon::now()->getTimestamp();
        $signature = $signer->sign($payload, $subscription->secret, $timestamp);

        $delivery->attempts = $this->attempts();
        $delivery->last_attempt_at = now();
        $delivery->status = 'failed';

        $result = null;

        try {
            // S1-04: re-validate immediately before every attempt (not just at
            // subscription create/update time) — DNS can be rebound from a
            // public IP to a private/metadata one between when the URL was
            // saved and when a retry actually fires, up to ~25 minutes later
            // per the backoff schedule below. Redirects are disabled so a
            // URL that passes this check can't 302 to an internal target, and
            // the resolved IP is pinned into the connection (below) so DNS
            // cannot be rebound between this check and the socket connect.
            $pinnedTarget = WebhookUrlGuard::resolvePinnedTarget($subscription->url);

            $result = $sender->send($subscription->url, $payload, $signature, $timestamp, $pinnedTarget);
        } catch (WebhookUrlBlockedException $e) {
            $delivery->forceFill([
                'status' => 'dead',
                'response_code' => null,
                'response_body' => substr('Blocked: '.$e->getMessage(), 0, 2000),
            ])->save();

            return;
        } catch (Throwable) {
            // Connection error — leave status as 'failed', job will be retried.
        }

        $this->applyResult($delivery, $result);
        $delivery->save();

        if ($result === null || ! $result->success) {
            throw new \RuntimeException("Webhook delivery to {$subscription->url} failed (attempt {$this->attempts()}).");
        }
    }

    private function applyResult(WebhookDelivery $delivery, ?WebhookSendResult $result): void
    {
        if ($result === null) {
            return;
        }

        $delivery->response_code = $result->responseCode;
        $delivery->response_body = $result->responseBody;

        if ($result->success) {
            $delivery->status = 'delivered';
        }
    }

    /**
     * Exponential backoff: 60 s, 120 s, 240 s, 480 s, 960 s between retries.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120, 240, 480, 960];
    }

    public function failed(Throwable $e): void
    {
        WebhookDelivery::where('id', $this->deliveryId)
            ->where('status', '!=', 'delivered')
            ->update(['status' => 'dead', 'updated_at' => now()]);
    }
}
