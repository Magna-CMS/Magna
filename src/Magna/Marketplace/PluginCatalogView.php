<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Plugins\PluginInfo;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Updater\UpdateCheck;
use Throwable;

/**
 * Builds everything the Plugins page shows: the installed list (records
 * merged with on-disk discovery, marketplace update checks, licence blocks
 * and publisher trust) and the browsable catalog of what is not yet
 * installed.
 *
 * Extracted from PluginsPage::refreshPlugins() (per the collaborator
 * pattern): a 130-line Livewire method that ran four Eloquent queries and
 * resolved PluginManager from the container three separate times was the
 * page doing a service's job. The page now asks for a snapshot; the merge
 * rules live here, constructor-injected and testable without Livewire.
 */
class PluginCatalogView
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly MarketplaceClient $marketplace,
    ) {}

    public function build(): PluginCatalogSnapshot
    {
        // Surface any pre-bundled (vendor/) plugins that were never installed
        // through the marketplace/zip flow, so they appear here and can be
        // enabled. Idempotent; only creates missing rows, always disabled.
        $this->plugins->syncDiscovered();

        $records = PluginRecord::query()->orderBy('display_name')->get();
        $installedNames = $records->pluck('name')->all();

        // Map discovered plugin versions for update detection
        $discoveredVersions = collect($this->plugins->discover())
            ->keyBy(fn (PluginInfo $info): string => $info->manifest->name)
            ->map(fn (PluginInfo $info): string => $info->manifest->version)
            ->all();

        $bootedPlugins = $this->plugins->getEnabled();

        // Updates a licence no longer covers. The marketplace deliberately
        // reports these as NOT available (the download would be refused), so
        // without this the admin would simply never hear that a newer version
        // exists — the worst way to learn a licence lapsed.
        $licenseBlocked = $this->latestVersionsBySlug(
            UpdateCheck::query()->where('type', 'plugin')->where('license_required', true),
        );

        // Versions published to the marketplace since this site installed.
        // Update detection used to compare the on-disk manifest against the
        // plugins row, which only ever notices files someone had ALREADY put
        // there — so publishing a new version of a paid plugin left this page
        // reading "Update Available (0)" while `magna:updater:check` was
        // reporting the very same update.
        $marketplaceUpdates = $this->latestVersionsBySlug(
            UpdateCheck::query()->where('type', 'plugin')->where('update_available', true)->whereNotNull('latest_version'),
        );

        // The catalog is read before the installed list is built: publisher
        // trust ("official") is something only the marketplace knows, so an
        // installed plugin's badge has to come from the same listing the
        // marketplace serves, keyed by package name.
        $catalog = $this->marketplace->plugins();
        $marketplaceUnreachable = $catalog === [] && $this->marketplace->wasUnreachable();

        /** @var array<string, PluginListing> $listingsByPackage */
        $listingsByPackage = collect($catalog)->keyBy(fn (PluginListing $l): string => $l->package)->all();

        $installed = [];
        foreach ($records as $r) {
            $settingsUrl = null;
            $booted = $bootedPlugins[$r->name] ?? null;
            if ($booted instanceof RegistersSettingsPages) {
                $pages = $booted->settingsPages();
                if ($pages !== []) {
                    try {
                        $settingsUrl = $pages[0]::getUrl();
                    } catch (Throwable) {
                    }
                }
            }

            $manifest = $r->manifest;
            $icon = $manifest['icon'] ?? null;
            $description = $manifest['description'] ?? null;
            $author = $manifest['author'] ?? null;

            $installed[] = [
                'name' => $r->name,
                'display_name' => $r->display_name,
                'version' => self::displayVersion($r->version),
                'enabled' => $r->enabled,
                'description' => is_string($description) ? $description : '',
                'author' => is_string($author) ? $author : '',
                'source' => str_contains(str_replace('\\', '/', (string) $r->base_path), '/plugins-dev/')
                    ? 'plugins-dev/'
                    : 'Composer',
                // Two ways a newer version shows up: someone put files on disk
                // (zip upload, manual copy), or the marketplace published one.
                // The marketplace answer wins when both are present — it is
                // the version the update button would actually fetch.
                'update_version' => self::displayVersion($marketplaceUpdates[$r->name]
                    ?? (isset($discoveredVersions[$r->name]) && $discoveredVersions[$r->name] !== $r->version
                        ? $discoveredVersions[$r->name]
                        : null)),
                'settings_url' => $settingsUrl,
                // magna.json's optional "icon" field, served through PluginIconController;
                // null when the plugin declared none — the view falls back to a letter avatar.
                'icon_url' => is_string($icon) && $icon !== '' ? route('plugins.icon', explode('/', $r->name, 2)) : null,
                // Set when a newer version exists that this site's licence
                // does not entitle it to — rendered as a renew prompt rather
                // than an Update button that cannot work.
                'license_blocked_version' => self::displayVersion($licenseBlocked[$r->name] ?? null),
                // Publisher trust from the marketplace listing, when this
                // plugin is one the marketplace knows about. A plugin sitting
                // in plugins-dev/ or installed by hand has no listing and
                // therefore claims nothing.
                'official' => $listingsByPackage[$r->name]->official ?? false,
                'verified' => $listingsByPackage[$r->name]->verified ?? false,
            ];
        }

        // "Add New" is the marketplace: browse the official catalog (not yet installed).
        $available = [];
        foreach ($catalog as $l) {
            if (in_array($l->package, $installedNames, true)) {
                continue;
            }

            $available[] = [
                'name' => $l->package,
                'display_name' => $l->name,
                'version' => self::displayVersion($l->version),
                'description' => $l->shortDescription,
                'author' => $l->author ?? '',
                'source' => 'Marketplace',
                'icon' => $l->icon,
                'permissions' => $l->permissions,
                'rating' => $l->rating,
                'ratings_count' => $l->ratingsCount,
                'website' => $l->website,
                // Commerce. A paid product is not installable by Composer —
                // it is bought here, and the licence is what fetches the
                // bytes. `prices` is term => minor units.
                'is_paid' => $l->isPaid(),
                'currency' => $l->currency,
                'prices' => $l->prices,
                'trial_enabled' => $l->trialEnabled && $l->isPaid(),
                'trial_days' => $l->trialDays,
                'seat_limit' => $l->seatLimit,
                'official' => $l->official,
                'verified' => $l->verified,
            ];
        }

        return new PluginCatalogSnapshot($installed, $available, $marketplaceUnreachable);
    }

    /** Versions render bare — "1.2.0", never "v1.2.0" — wherever they appear. */
    public static function displayVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        return ltrim($version, 'vV');
    }

    /**
     * slug => latest_version for the given update-check scope, with honest
     * string types (pluck() would hand back mixed on both sides).
     *
     * @param  Builder<UpdateCheck>  $query
     * @return array<string, string>
     */
    private function latestVersionsBySlug(Builder $query): array
    {
        $versions = [];

        foreach ($query->get(['slug', 'latest_version']) as $check) {
            if (is_string($check->slug) && is_string($check->latest_version)) {
                $versions[$check->slug] = $check->latest_version;
            }
        }

        return $versions;
    }
}
