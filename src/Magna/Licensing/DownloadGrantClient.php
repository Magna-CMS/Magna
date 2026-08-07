<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Support\Facades\Http;
use Magna\Marketplace\Marketplace;
use Magna\Support\InstallFingerprint;
use Throwable;

/**
 * Trades an activation token for a short-lived, single-use download grant.
 *
 * Split out of LicenseClient for two reasons. The mechanical one: the client
 * had grown past the class-size ceiling. The real one: this call carries
 * per-request failure state — WHY the marketplace refused — and that state has
 * no business living on a client shared by every licensing surface. Every
 * refusal used to collapse into the same null, so the panel said "the licence
 * server did not authorise a download" alike for a stale token, an unapproved
 * package, a lapsed entitlement, an outage and a response that failed
 * signature verification: five different fixes behind one sentence nobody
 * could act on. The reason has to survive the trip.
 */
class DownloadGrantClient
{
    /** Set by grant() so the caller can say which refusal it hit. */
    private ?string $lastError = null;

    /**
     * @return array<array-key, mixed>|null the grant, or null with lastError() set
     */
    public function grant(string $token): ?array
    {
        $this->lastError = null;

        try {
            $response = Http::timeout(Marketplace::REQUEST_TIMEOUT)->acceptJson()->asJson()
                ->post(Marketplace::API_BASE.'/license/download-url', [
                    'token' => $token,
                    'fingerprint' => InstallFingerprint::derive(),
                ]);
        } catch (Throwable) {
            $this->lastError = 'the licence server could not be reached';

            return null;
        }

        if (! $response->successful()) {
            $message = $response->json('message');
            $code = $response->json('error');

            $this->lastError = is_string($message) && $message !== ''
                ? $message.(is_string($code) && $code !== '' ? ' ('.$code.')' : '')
                : 'the licence server answered '.$response->status();

            return null;
        }

        $payload = SignedPayload::open($this->stringKeyed($response->json()));

        if ($payload === null) {
            $this->lastError = 'the response could not be verified against the licence key this build carries';

            return null;
        }

        $grant = is_array($payload['download'] ?? null) ? $payload['download'] : null;

        if ($grant === null) {
            $this->lastError = 'the response carried no download grant';
        }

        return $grant;
    }

    /** Why the last grant() call came back empty, or null when it did not. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * The envelope as SignedPayload expects it: JSON objects decode to
     * string-keyed arrays, and anything else is not an envelope at all.
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        $envelope = [];

        foreach ($json as $key => $value) {
            if (is_string($key)) {
                $envelope[$key] = $value;
            }
        }

        return $envelope;
    }
}
