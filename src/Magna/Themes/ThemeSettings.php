<?php

declare(strict_types=1);

namespace Magna\Themes;

use Magna\Settings\Settings;

/**
 * Which installed theme this site is presenting.
 *
 * Magna is API-first: nothing in the admin renders a theme. The active theme
 * is published through the Delivery API so a decoupled front end can fetch
 * the templates and assets it should be using — which is why this is a
 * setting rather than a runtime binding.
 */
class ThemeSettings extends Settings
{
    /** vendor/name of the active theme, or null when none is active. */
    public ?string $active = null;
}
