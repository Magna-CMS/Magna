<?php

declare(strict_types=1);

namespace Magna\Support;

use Illuminate\Contracts\Config\Repository;
use Laravel\Octane\Octane;

/**
 * Keeps Octane's own service defaults in the warm list, whatever the site's
 * config file says.
 *
 * ApplicationFactory::warm() reads `octane.warm` INSTEAD of
 * Octane::defaultServicesToWarm(), not in addition to it. A site whose file
 * lists only its own additions has therefore dropped `auth` (and router,
 * session, cache and the rest) from worker-boot warming — and the Gate's user
 * resolver is a closure over the container that built the Gate, the base. On
 * a worker where no boot-time code happened to touch `auth`, the gate asked a
 * second, userless AuthManager while the request carried a signed-in user,
 * and every policy denied as if for a guest: "This action is unauthorized."
 * to people who plainly held the ability, a core super admin included. Per
 * worker, healed by recycling. One licensed install chased that for two days.
 *
 * Shipping the corrected config file (1.6.2) fixes fresh installs only: an
 * update never overwrites `config/*.php` — the release manifest protects
 * them — and ConfigDefaults backfills only keys a site's file LACKS,
 * deliberately never touching lists, because a list is usually a deliberate
 * narrowing. This list is the documented exception: entries in Octane's own
 * default set are not a narrowing anyone means, they are residency plumbing,
 * and their absence is indistinguishable from this exact bug. So the merge
 * lives here, under `src/Magna`, which an update DOES overlay — and it only
 * ever ADDS Octane's defaults back; a site's own additions are untouched.
 *
 * Runs in register(), which is before Octane's worker warm-up reads the key:
 * providers register during bootstrap, and ApplicationFactory::warm() runs on
 * the bootstrapped application.
 */
final class OctaneWarmList
{
    /** No-op wherever Octane is not installed — the list is read by nothing else. */
    public static function ensureDefaults(Repository $config): void
    {
        if (! class_exists(Octane::class)) {
            return;
        }

        /** @var mixed $warm */
        $warm = $config->get('octane.warm', []);

        if (! is_array($warm)) {
            return;
        }

        // Compared against the strings only; whatever else a site put in the
        // list rides along untouched in the merge below.
        $present = array_values(array_filter($warm, 'is_string'));

        $missing = array_diff(Octane::defaultServicesToWarm(), $present);

        if ($missing === []) {
            return;
        }

        $config->set('octane.warm', array_merge($warm, array_values($missing)));
    }
}
