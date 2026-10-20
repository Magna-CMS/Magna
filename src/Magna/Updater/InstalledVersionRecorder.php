<?php

declare(strict_types=1);

namespace Magna\Updater;

use Magna\Support\Version;

/**
 * Marks a plugin's or theme's recorded update as taken.
 *
 * The Plugins and Themes pages and the dashboard badge read `update_checks`,
 * which is written by the 12-hourly check-in. Installing the update never
 * touched that row, so the panel went on advertising the same version the
 * admin had just installed — for up to twelve hours, with an Update button
 * that would install a version already present. Anyone reasonably reads that
 * as the update having failed.
 *
 * Called on a successful install/update rather than re-checking with the
 * marketplace, because the answer is already known locally: the version that
 * was installed. `license_required` is cleared for the same reason — whatever
 * it was warning about no longer describes what is on disk.
 */
final class InstalledVersionRecorder
{
    public function record(string $package, string $installedVersion, string $type = 'plugin'): void
    {
        $check = UpdateCheck::query()
            ->where('type', $type)
            ->where('slug', $package)
            ->first();

        if ($check === null) {
            return;
        }

        // A newer version may exist beyond the one installed (a site can be two
        // releases behind), so this does not blindly clear the flag: it answers
        // "is there still something newer than what is now on disk". Through
        // Version, because the installer passes the catalog's tag (v1.1.0) and
        // a raw version_compare against a bare latest got the answer backwards.
        $check->forceFill([
            'current_version' => Version::bare($installedVersion) ?? $installedVersion,
            'update_available' => Version::isNewer($check->latest_version, $installedVersion),
            'license_required' => false,
            'checked_at' => now(),
        ])->save();
    }
}
