<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gets this site a fresh activation without anybody typing a licence key.
 *
 * A site activates once, at install, and the key is deliberately never stored —
 * a stored key is a stealable key. So when the marketplace later stops
 * recognising the activation, the site has no way back and an operator is asked
 * for a credential they entered months ago. That is the report this exists to
 * answer: "we activated with the key when we installed it, why is it asking
 * again?".
 *
 * It does not need the key. The account this site is connected to owns the
 * licence, and /account/licenses/{id}/install issues a fresh activation token
 * against it — the same call the Licences card makes. Only the token is kept:
 * the bytes are already on disk, so there is nothing to download.
 *
 * An activation that is gone because the entitlement ended is not repaired here:
 * the marketplace refuses to issue a token for a revoked or expired licence, and
 * this reports that refusal rather than papering over it.
 */
class LicenseReactivator
{
    /**
     * Attempts are throttled per product: the licence policy asks on request,
     * and a marketplace that is down or a licence that is genuinely dead must
     * not turn every page view into an outbound HTTP call.
     */
    private const RETRY_MINUTES = 60;

    public function __construct(
        private readonly LicenseClient $client,
        private readonly LicenseStore $store,
    ) {}

    /**
     * Refreshes the stored activation for a product from the connected account.
     *
     * Returns true only when a new token was obtained and stored.
     */
    public function refresh(string $productSlug): bool
    {
        $throttle = 'magna.licensing.reactivate.'.$productSlug;

        if (Cache::get($throttle) !== null) {
            return false;
        }

        Cache::put($throttle, true, now()->addMinutes(self::RETRY_MINUTES));

        $licenseId = $this->walletLicenseIdFor($productSlug);

        if ($licenseId === null) {
            return false;
        }

        try {
            $payload = $this->client->install($licenseId);
        } catch (Throwable $e) {
            Log::info('Licence reactivation could not reach the marketplace.', [
                'product' => $productSlug,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $token = $payload['token'] ?? null;

        if ($payload === null || ! is_string($token) || $token === '') {
            return false;
        }

        $license = is_array($payload['license'] ?? null) ? $payload['license'] : [];
        $status = is_string($license['status'] ?? null) ? $license['status'] : 'active';

        // A refusal for a dead licence is not something to store as if it were
        // an activation.
        if (! in_array($status, ['active', 'trial', 'past_due'], true)) {
            return false;
        }

        $this->store->put(new LicenseEntry(
            productSlug: $productSlug,
            token: $token,
            status: $status,
            licenseType: is_string($license['license_type'] ?? null) ? $license['license_type'] : 'unknown',
            expiresAt: LicenseEntry::parseDate($license['license_expires_at'] ?? null),
            updateEntitled: true,
            serverTime: LicenseEntry::parseDate($payload['server_time'] ?? null) ?? Carbon::now(),
            lastVerifiedAt: Carbon::now(),
        ));

        Log::info('Licence reactivated from the connected Magna Account.', ['product' => $productSlug]);

        return true;
    }

    /** The wallet licence id for a product, or null when the account does not hold one. */
    private function walletLicenseIdFor(string $productSlug): ?int
    {
        foreach ($this->client->wallet() ?? [] as $licence) {
            if (($licence['product_slug'] ?? null) !== $productSlug) {
                continue;
            }

            if (is_numeric($licence['id'] ?? null)) {
                return (int) $licence['id'];
            }
        }

        return null;
    }
}
