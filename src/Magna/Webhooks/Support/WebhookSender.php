<?php

declare(strict_types=1);

namespace Magna\Webhooks\Support;

use Illuminate\Support\Facades\Http;

/** Sends one signed webhook HTTP POST and reports the outcome. */
class WebhookSender
{
    /**
     * @param  array{host: string, port: int, ip: string}|null  $pinnedTarget  When
     *                                                                         supplied (from WebhookUrlGuard::resolvePinnedTarget), the connection is
     *                                                                         pinned to this pre-validated IP so DNS cannot be rebound to an internal
     *                                                                         target between the SSRF check and this send.
     */
    public function send(string $url, string $payload, string $signature, int $timestamp, ?array $pinnedTarget = null): WebhookSendResult
    {
        $options = ['allow_redirects' => false];

        if ($pinnedTarget !== null) {
            // CURLOPT_RESOLVE forces cURL to connect to the checked IP while
            // leaving the Host header, TLS SNI, and certificate validation on
            // the original hostname — the request goes to exactly the address
            // WebhookUrlGuard approved, not whatever DNS answers at connect time.
            $options['curl'] = [
                CURLOPT_RESOLVE => [
                    sprintf('%s:%d:%s', $pinnedTarget['host'], $pinnedTarget['port'], $pinnedTarget['ip']),
                ],
            ];
        }

        $response = Http::timeout(10)
            ->withOptions($options)
            ->withHeaders([
                'X-Magna-Signature-256' => $signature,
                'X-Magna-Timestamp' => (string) $timestamp,
            ])
            ->withBody($payload, 'application/json')
            ->post($url);

        return new WebhookSendResult(
            responseCode: $response->status(),
            responseBody: substr($response->body(), 0, 2000),
            success: $response->successful(),
        );
    }
}
