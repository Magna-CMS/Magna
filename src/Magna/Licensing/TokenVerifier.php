<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Support\Facades\Http;
use Magna\Marketplace\Marketplace;
use Magna\Support\InstallFingerprint;
use Throwable;

/**
 * The ~daily phone-home, answered with refusal and outage kept apart.
 *
 * Split out of LicenseClient for the same two reasons DownloadGrantClient was:
 * the client had grown past the class-size ceiling, and the distinction this
 * call carries — WHY there is no payload — is this call's own concern. An
 * outage is covered by grace and cured by waiting; a refusal is cured by
 * acting and never by waiting. Collapsing both into one null is how a
 * production site held a dead token through four days of heartbeats, each one
 * mistaking the marketplace's "no" for silence.
 *
 * A response whose signature does not verify still counts as an outage — a
 * forged refusal must not be able to talk a site into abandoning its token.
 * See VerifyOutcome for the whole argument.
 */
class TokenVerifier
{
    public function outcome(string $token): VerifyOutcome
    {
        try {
            $response = Http::timeout(Marketplace::REQUEST_TIMEOUT)->acceptJson()->asJson()
                ->post(Marketplace::API_BASE.'/license/verify', [
                    'token' => $token,
                    'fingerprint' => InstallFingerprint::derive(),
                ]);
        } catch (Throwable) {
            return new VerifyOutcome(null);
        }

        if ($response->successful()) {
            $json = $response->json();

            return new VerifyOutcome(SignedPayload::open($this->stringKeyed(is_array($json) ? $json : [])));
        }

        // A refusal is an answered request that said no. A 5xx, or a 4xx
        // carrying no error code, is not one — as far as this site may know,
        // that is the outage shape, and grace covers it.
        if ($response->status() >= 400 && $response->status() < 500) {
            $code = $response->json('error');

            if (is_string($code) && $code !== '') {
                return new VerifyOutcome(null, $code);
            }
        }

        return new VerifyOutcome(null);
    }

    /**
     * @param  array<array-key, mixed>  $json
     * @return array<string, mixed>
     */
    private function stringKeyed(array $json): array
    {
        $keyed = [];

        foreach ($json as $key => $value) {
            if (is_string($key)) {
                $keyed[$key] = $value;
            }
        }

        return $keyed;
    }
}
