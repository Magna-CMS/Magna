<?php

declare(strict_types=1);

namespace Magna\Licensing\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Magna\Licensing\LicenseEnforcer;
use Magna\Licensing\LicenseGuard;
use Magna\Licensing\LicenseStore;
use Magna\Marketplace\Marketplace;

/**
 * The daily phone-home, and the enforcement pass that follows it.
 *
 * Verifying without enforcing would be theatre: this command is the reason a
 * cancelled corporate licence actually stops the product on the client's
 * site rather than merely being recorded as cancelled.
 *
 * Never fails the schedule run on an unreachable server — that is precisely
 * the case offline grace exists for, and enforcement runs off cached
 * server-signed state either way.
 *
 * Usage:
 *   php artisan magna:licensing:verify
 */
class VerifyLicensesCommand extends Command
{
    protected $signature = 'magna:licensing:verify';

    protected $description = 'Re-verify this site\'s licences and enforce any that have ended';

    public function handle(LicenseGuard $guard, LicenseStore $store, LicenseEnforcer $enforcer): int
    {
        // A build shipped without its Ed25519 public key fails every signature
        // check, which looks exactly like an unreachable server — say so
        // instead of letting the site sit in grace until it expires.
        if (! Marketplace::hasUsableLicenseKey()) {
            $this->error('This build has no usable licence public key — every licence response will fail verification. Rebuild from an official release, or set MAGNA_LICENSE_PUBLIC_KEY outside production.');
            Log::critical('Licensing: no usable Ed25519 public key is configured; all licence verification will fail.');

            return self::FAILURE;
        }

        $total = count($store->all());

        if ($total === 0) {
            $this->info('No licensed products on this site.');

            return self::SUCCESS;
        }

        $refreshed = $guard->refreshAll();

        if ($refreshed < $total) {
            $this->warn(($total - $refreshed).' of '.$total.' licence(s) could not be re-verified — running on cached state.');
        } else {
            $this->info($refreshed.' licence(s) re-verified.');
        }

        $result = $enforcer->sync();

        foreach ($result['locked'] as $slug) {
            $this->warn("Disabled {$slug} — its licence is no longer valid.");
        }

        foreach ($result['restored'] as $slug) {
            $this->info("Re-enabled {$slug} — its licence is valid again.");
        }

        return self::SUCCESS;
    }
}
