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

    /**
     * True when this plugin must not load — its licence has ended, been
     * withdrawn, or (for a plugin that arrived through the licensed download
     * path) is simply gone.
     */
    public function isLocked(string $productSlug, bool $requiresLicense = false): bool
    {
        return $this->stateOf($productSlug, $requiresLicense) === LicenseState::Locked;
    }

    /**
     * Cache-only state resolution. Mirrors LicenseGuard's policy without its
     * network fallbacks: a product with no cached entry is NOT locked here,
     * because free plugins (the overwhelming majority) have no licence at
     * all and must boot normally.
     *
     * $requiresLicense flips that default for plugins installed through the
     * licensed download path (PluginRecord::$requires_license). Releasing a
     * seat both frees it on the marketplace AND forgets the local entry, so
     * without this a paid plugin kept running while its key moved to the next
     * domain — release, re-activate, repeat, and one seat quietly powered any
     * number of sites. A missing entry for such a plugin is now a lock, not a
     * licence-free plugin.
     */
    public function stateOf(string $productSlug, bool $requiresLicense = false): LicenseState
    {
        $entry = $this->store->get($productSlug);

        if ($entry === null) {
            return $requiresLicense ? LicenseState::Locked : LicenseState::Unlicensed;
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
