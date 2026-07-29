<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Magna\Settings\Attributes\Secret;
use Magna\Settings\Settings;

/**
 * This site's licence cache — activation tokens plus the last verified state
 * for every paid product installed here.
 *
 * Stored as one #[Secret] JSON blob so it rides the same encrypted settings
 * store as the Magna Account token (Magna\Settings\SettingsRepository): a
 * database dump never exposes an activation token in plaintext. Read and
 * written only through LicenseStore, never touched directly.
 *
 * The marketplace is always the source of truth; this is a cache that lets a
 * site keep working while the licence server is unreachable (the offline
 * grace window in LicenseGuard).
 */
class LicenseSettings extends Settings
{
    /** JSON map of product slug => cached licence entry. See LicenseStore. */
    #[Secret]
    public ?string $entries = null;
}
