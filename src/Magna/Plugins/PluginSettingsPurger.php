<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Support\Facades\Cache;
use Magna\Settings\Setting;

/**
 * Deletes the settings rows a plugin owns when it is uninstalled with --purge.
 *
 * A plugin's own tables are dropped from its manifest's uninstall.tables, but
 * its settings live in core's shared `settings` table keyed by group — for a
 * plugin like Magna Defence that includes encrypted secrets, which would
 * otherwise be orphaned in the database forever. A plugin declares the groups
 * it owns under uninstall.settingsGroups; this removes exactly those.
 *
 * Core setting groups are refused defensively: a malformed or hostile manifest
 * must not be able to wipe core configuration on its own uninstall.
 */
final class PluginSettingsPurger
{
    /**
     * Group slugs owned by core Settings classes (Settings::group() =
     * snake_case of the class basename minus "Settings"). A plugin manifest may
     * never purge these.
     *
     * @var list<string>
     */
    private const CORE_GROUPS = [
        'general', 'mail', 'media', 'backup', 'content', 'api', 'url',
        'localization', 'performance', 'storage', 'security', 'license',
        'theme', 'account_centre',
    ];

    public function purge(PluginRecord $record): void
    {
        /** @var mixed $manifest */
        $manifest = $record->manifest;
        if (! is_array($manifest)) {
            return;
        }

        $uninstall = $manifest['uninstall'] ?? null;
        if (! is_array($uninstall)) {
            return;
        }

        $groups = $uninstall['settingsGroups'] ?? [];
        if (! is_array($groups)) {
            return;
        }

        foreach ($groups as $group) {
            if (! is_string($group) || $group === '' || in_array($group, self::CORE_GROUPS, true)) {
                continue;
            }

            Setting::query()->where('group', $group)->delete();
            Cache::forget("magna-settings:{$group}");
        }
    }
}
