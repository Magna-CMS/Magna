<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Support\Facades\Artisan;

/**
 * Copies every registered Filament asset out of vendor/ into public/.
 *
 * A plugin registers its admin scripts and styles with FilamentAsset at boot,
 * but they are only served once `filament:assets` has copied them — a step
 * nothing ran after a marketplace install. Its own class so the installer
 * can be tested without running the command, and so a failure here stays a
 * failure of THIS step: the caller decides what it means for the install.
 */
class AdminAssetPublisher
{
    public function publish(): void
    {
        Artisan::call('filament:assets');
    }
}
