<?php

declare(strict_types=1);

namespace Magna\Admin;

use Illuminate\Contracts\Console\Kernel;

/**
 * Moves the panel between "/" and "/admin", and leaves nothing stale behind.
 *
 * Saving the setting is the easy half. The panel's routes are built from that
 * value during the provider's register(), so a site running `route:cache`
 * would keep serving the old path from the compiled file until someone
 * cleared it by hand — the switch would appear to do nothing, which is the
 * worst possible outcome for a setting whose whole job is moving the way back
 * in. Clearing the route cache here makes the change true on the very next
 * request.
 *
 * The caller redirects: the URL it is currently on stops existing the moment
 * this returns.
 */
final class PanelPathSwitcher
{
    public function __construct(private readonly Kernel $console) {}

    /** Put the panel at /admin (true) or back at the domain root (false). */
    public function set(bool $adminPrefix): void
    {
        $settings = PanelSettings::get();

        if ($settings->admin_prefix === $adminPrefix) {
            return;
        }

        $settings->admin_prefix = $adminPrefix;
        $settings->save();

        // Routes only; config and views hold nothing that depends on this,
        // and `optimize:clear` would drop an unrelated site's warm caches for
        // a one-line change.
        $this->console->call('route:clear');
    }

    /** Where the panel answers now, as a path a browser can be sent to. */
    public function url(): string
    {
        return PanelPath::current() === PanelPath::ROOT ? '/' : '/'.PanelPath::current();
    }
}
