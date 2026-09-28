<?php

declare(strict_types=1);

namespace Magna\Admin;

use Magna\Settings\Settings;

/**
 * Where the admin panel answers.
 *
 * A boolean rather than a free path, on purpose. The panel is the only way
 * back to this setting, so a typo in a text field would strand every admin
 * behind a URL nobody could guess; a switch can only ever be in one of two
 * places, and `magna:panel:path` can put it back from a shell either way.
 *
 * Off is the shipped behaviour and stays the default for every existing
 * install: the panel keeps answering at "/". Turning it on frees "/" for a
 * frontend plugin's own routes (Magna Pages serves the site's home page from
 * its fallback route) and moves the panel to "/admin" — the arrangement most
 * people arrive expecting.
 */
class PanelSettings extends Settings
{
    /** True when the panel answers at /admin and "/" belongs to the site. */
    public bool $admin_prefix = false;
}
