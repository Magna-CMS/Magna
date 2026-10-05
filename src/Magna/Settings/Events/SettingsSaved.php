<?php

declare(strict_types=1);

namespace Magna\Settings\Events;

/**
 * A settings group was written.
 *
 * Fired by SettingsRepository::persist(), which is the single write path for
 * every typed settings aggregate — so a listener hears about a change whatever
 * screen, command or job made it.
 *
 * It exists because a settings value can be baked into something cached
 * elsewhere. The site name is the case that forced it: Magna Pages renders it
 * into every page title and caches the result for an hour, so renaming the
 * site from the admin left the old name on the public site long after the
 * admin had moved on. Core cannot flush that cache — it belongs to a plugin
 * core must not know about — and the plugin cannot poll for a change. An event
 * is the seam.
 *
 * The group, not the values: a listener that needs the new values reads them
 * from the typed class, which is already fresh by the time this fires. Putting
 * them in the payload would mean deciding here what a secret-bearing group may
 * broadcast.
 */
final class SettingsSaved
{
    /**
     * @param  string  $group  the settings group's name, as Settings::group() spells it (e.g. "general", "pages")
     */
    public function __construct(public readonly string $group) {}
}
