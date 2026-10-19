<?php

declare(strict_types=1);

namespace Magna\Themes;

use Magna\Plugins\PluginRecord;
use Throwable;

/**
 * What plugin versions this site is actually running, for a theme's
 * `compat.plugins` floor to be checked against.
 *
 * ENABLED, not merely installed. A theme's rendering depends on a plugin
 * doing the rendering: an installed-but-disabled magna/pages draws nothing at
 * all, so counting it as satisfying a floor would pass the check and still
 * produce the broken page the check exists to prevent.
 *
 * Its own class rather than a query inside ThemeManager, because "which
 * plugins are running" is a different question from "which themes are on
 * disk", and because a theme test needs to state the answer without a plugin
 * installation.
 */
class EnabledPluginVersions
{
    /**
     * Enabled plugin name to version, or NULL when that cannot be read.
     *
     * The null is load-bearing and is not the same as an empty list. The
     * plugins table does not exist before the installer has run, and a
     * theme is activated during installation — so a store that cannot be
     * read must leave the floor unchecked rather than refuse. Refusing on
     * unknown state would mean a site that cannot activate its own theme
     * and has no way back, which is a worse failure than the layout bug the
     * floor guards against. Same reasoning as PanelPath::current()
     * resolving to root when the settings store is unreachable.
     *
     * @return array<string, string>|null
     */
    public function all(): ?array
    {
        try {
            $records = PluginRecord::query()
                ->where('enabled', true)
                ->get(['name', 'version']);
        } catch (Throwable) {
            return null;
        }

        $versions = [];

        foreach ($records as $record) {
            // Neither is nullable in the schema; the emptiness check is for a
            // row written before a version was known, which would otherwise
            // satisfy no constraint and read as a floor failure with an empty
            // version in the message.
            if ($record->name !== '' && $record->version !== '') {
                $versions[$record->name] = $record->version;
            }
        }

        return $versions;
    }
}
