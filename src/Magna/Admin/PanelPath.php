<?php

declare(strict_types=1);

namespace Magna\Admin;

use Illuminate\Support\Facades\Schema;
use Magna\Install\Installer;
use Throwable;

/**
 * The path AdminPanelProvider hands Filament.
 *
 * Read during the panel provider's register(), which is earlier than any
 * other settings read in the application — earlier than
 * PerformanceServiceProvider's, which runs in boot(). The guard ladder is the
 * same one and for the same reason: a fresh unzip has no database and may not
 * even have a driver enabled, a just-cloned checkout has no `settings` table,
 * and neither may turn the panel into a 500. Anything unexpected resolves to
 * the root path, because a panel at "/" is the behaviour every install
 * already had and the one an operator can always reach.
 *
 * ROOT is deliberately the failure value rather than PREFIX: if the settings
 * store is unreadable, an operator who had switched to /admin finds the panel
 * back at "/" — surprising, but reachable. The reverse would hide it.
 */
final class PanelPath
{
    /** Filament's own spelling for "serve this panel from the domain root". */
    public const ROOT = '';

    /** Where the panel moves when an operator frees the root for their site. */
    public const PREFIX = 'admin';

    public static function current(): string
    {
        return self::enabled() ? self::PREFIX : self::ROOT;
    }

    /** Whether the panel has been moved off the domain root. */
    public static function enabled(): bool
    {
        // Before installation there is no usable database, and asking one for
        // a table is itself a connection attempt — "could not find driver"
        // instead of the installer's first screen.
        if (! Installer::isInstalled()) {
            return false;
        }

        try {
            if (! Schema::hasTable('settings')) {
                return false;
            }

            return PanelSettings::get()->admin_prefix;
        } catch (Throwable) {
            // A settings store that is unreachable, misconfigured or mid
            // migration must not decide where the panel lives. Root wins.
            return false;
        }
    }
}
