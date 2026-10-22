<?php

declare(strict_types=1);

namespace Magna\Admin\Concerns;

use Filament\Notifications\Notification;
use Magna\Marketplace\MarketplaceClient;

/**
 * The "See more" details view on marketplace cards — the Plugins page's
 * catalog and the Themes page's Browse tab.
 *
 * A card has room for a name, a line of description and a price; deciding
 * whether to install something takes more than that. This loads the full
 * listing (screenshots, description, reviews and how the stars are spread,
 * publisher links) into one public property the shared modal partial
 * renders, store-listing style.
 *
 * The client arrives by Livewire method injection, not a container lookup:
 * both pages that use this have their lookup counts pinned by the
 * architecture ratchet.
 */
trait ShowsMarketplaceDetails
{
    /** @var array<string, mixed>|null the open listing, or null when closed */
    public ?array $details = null;

    public function showDetails(string $package, MarketplaceClient $marketplace): void
    {
        $details = $marketplace->details($package);

        if ($details === null) {
            Notification::make()
                ->title('Could not load the details')
                ->body('The marketplace did not answer for this listing. Try again in a moment.')
                ->warning()
                ->send();

            return;
        }

        $this->details = $details->toArray();
    }

    public function closeDetails(): void
    {
        $this->details = null;
    }
}
