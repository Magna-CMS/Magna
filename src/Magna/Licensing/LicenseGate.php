<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Support\Carbon;

/**
 * The boot-time licence check — deliberately OFFLINE.
 *
 * PluginManager::bootEnabledPlugins() consults this on every request, so it
 * may never make an HTTP call, never throw, and never be slow: it reads only
 * the cached state that the daily verify (LicenseGuard) wrote. That cached
 * state is itself server-signed, which is what makes trusting it locally
 * safe.
 *
 * The consequence is that enforcement is as immediate as the verify cadence:
 * once the licence server reports a corporate licence cancelled, the next
 * verify (daily, or the admin's "Re-check now") locks it, and from that
 * moment the plugin does not boot at all.
 */
class LicenseGate
{
    public function __construct(private readonly LicenseStore $store) {}

    /** True when this plugin must not load — its licence has ended or been withdrawn. */
    public function isLocked(string $productSlug): bool
    {
        return $this->stateOf($productSlug) === LicenseState::Locked;
    }

    /**
     * Cache-only state resolution. Mirrors LicenseGuard's policy without its
     * network fallbacks: a product with no cached entry is NOT locked here,
     * because free plugins (the overwhelming majority) have no licence at
     * all and must boot normally.
     */
    public function stateOf(string $productSlug): LicenseState
    {
        $entry = $this->store->get($productSlug);

        if ($entry === null) {
            return LicenseState::Unlicensed;
        }

        return match ($entry->status) {
            'revoked', 'suspended' => LicenseState::Locked,
            'expired' => $entry->endsOnExpiry() ? LicenseState::Locked : LicenseState::Expired,
            default => $this->fromExpiry($entry),
        };
    }

    /** @return array<string, LicenseEntry> every product currently locked, keyed by slug */
    public function locked(): array
    {
        return array_filter(
            $this->store->all(),
            fn (LicenseEntry $entry): bool => $this->stateOf($entry->productSlug) === LicenseState::Locked,
        );
    }

    /**
     * A cached "active" whose server-signed expiry has since passed is
     * treated as expired without waiting for the server to say so — the
     * expiry date came from a signed payload, so it is as trustworthy as
     * the status itself.
     */
    private function fromExpiry(LicenseEntry $entry): LicenseState
    {
        if ($entry->expiresAt !== null && $entry->expiresAt->lt(Carbon::now())) {
            return $entry->endsOnExpiry() ? LicenseState::Locked : LicenseState::Expired;
        }

        return LicenseState::Valid;
    }
}
