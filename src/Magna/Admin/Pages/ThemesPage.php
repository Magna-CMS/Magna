<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Magna\Licensing\Concerns\ChecksOutWithRazorpay;
use Magna\Licensing\LicenseInstaller;
use Magna\Marketplace\MarketplaceClient;
use Magna\Marketplace\PluginCatalogView;
use Magna\Marketplace\PluginListing;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeManifest;
use Magna\Updater\UpdateCheck;
use Magna\Updater\UpdateCheckClient;
use Throwable;

/**
 * Themes: what is installed, what is active, and what can be bought.
 *
 * A theme is presentation for whatever consumes the Delivery API — Magna
 * itself renders nothing from it. So "active" is a published fact rather
 * than a runtime binding, and switching one can never break the admin.
 *
 * Buying works exactly as it does for plugins (same trait, same gateway,
 * same webhook-settled order); only the install target differs, and that is
 * decided by the package's own manifest rather than by this page.
 */
class ThemesPage extends Page
{
    use ChecksOutWithRazorpay;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Themes';

    protected static ?string $title = 'Themes';

    protected static ?int $navigationSort = 42;

    protected static ?string $slug = 'themes';

    protected string $view = 'magna::admin.themes';

    public string $activeTab = 'installed';

    /** @var list<array<string, mixed>> */
    public array $available = [];

    public bool $marketplaceUnreachable = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->loadCatalog(false);
    }

    // The blade renders its own header, same as the Plugins page.
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $themes = $this->themes();
        $installed = $themes->installed();
        $activeName = $themes->active()?->name;
        $activeAddonNames = array_map(
            fn (ThemeManifest $a): string => $a->name,
            $themes->activeAddons(),
        );

        // What the check-in recorded for themes: a newer marketplace version
        // (rendered as an Update button) or one the licence no longer covers
        // (rendered as a renew prompt) — the same split the Plugins page makes.
        $checks = UpdateCheck::query()->where('type', 'theme')->get()->keyBy('slug');
        $updateFields = function (ThemeManifest $t) use ($checks): array {
            $check = $checks->get($t->name);

            return [
                'update_version' => $check?->update_available === true
                    ? PluginCatalogView::displayVersion($check->latest_version)
                    : null,
                'license_blocked_version' => $check?->license_required === true
                    ? PluginCatalogView::displayVersion($check->latest_version)
                    : null,
            ];
        };

        return [
            'installed' => array_map(
                fn (ThemeManifest $t): array => [
                    'name' => $t->name,
                    'display_name' => $t->displayName,
                    'description' => $t->description,
                    'version' => $t->version,
                    'author' => $t->author,
                    'tags' => $t->tags,
                    'homepage' => $t->homepage,
                    'active' => $t->name === $activeName,
                ] + $updateFields($t),
                array_values(array_filter($installed, fn (ThemeManifest $t): bool => ! $t->isAddon())),
            ),
            // Addons apply automatically alongside their host — this list
            // shows WHAT applies and, for the inactive ones, why not.
            'addons' => array_map(
                fn (ThemeManifest $a): array => [
                    'name' => $a->name,
                    'display_name' => $a->displayName,
                    'description' => $a->description,
                    'version' => $a->version,
                    'extends' => (string) $a->extends,
                    'pairs_with' => $a->pairsWith,
                    'applies' => in_array($a->name, $activeAddonNames, true),
                ] + $updateFields($a),
                array_values(array_filter($installed, fn (ThemeManifest $t): bool => $t->isAddon())),
            ),
            // Anything already on disk is not for sale again.
            'available' => array_values(array_filter(
                $this->available,
                fn (array $t): bool => ! (is_string($t['name'] ?? null) && isset($installed[$t['name']])),
            )),
            'activeName' => $activeName,
        ];
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['installed', 'browse'], true) ? $tab : 'installed';
    }

    /**
     * Pull the marketplace's theme catalog — and, on a button press, run an
     * update check-in too.
     *
     * `$fresh` is what the Refresh button passes and what a page load does
     * not. A catalog response stands for an hour, so without it the button
     * re-read the cache and showed exactly what was already on screen: a
     * theme published to the marketplace minutes ago stayed missing from
     * Browse, and there was no way to correct that short of waiting out the
     * TTL or clearing the cache from a shell. A page load still reads the
     * cache, because opening a screen is not a request to re-fetch.
     *
     * The check-in matters because the Installed tab's "Update now" banner
     * reads update_checks rows, and only UpdateCheckClient::checkIn() writes
     * those — on its 12-hour schedule. Without it, Refresh rebuilt Browse and
     * left the banner stale, which is the one thing the button is pressed
     * for; and since Browse hides installed themes, the click looked like a
     * no-op. A page load still never checks in, for the same reason it never
     * re-fetches.
     */
    public function refreshCatalog(UpdateCheckClient $updates, bool $fresh = false): void
    {
        $this->loadCatalog($fresh);

        if (! $fresh) {
            return;
        }

        // A dead network or a throttled manager (the check-in endpoint is
        // rate-limited server-side) degrades to a catalog-only refresh with a
        // warning — never a broken page.
        try {
            $checkedIn = $updates->checkIn() !== null;
        } catch (Throwable) {
            $checkedIn = false;
        }

        if ($this->marketplaceUnreachable || ! $checkedIn) {
            Notification::make()
                ->title('Could not reach the marketplace — showing what is known.')
                ->warning()
                ->send();

            return;
        }

        $updatesFound = UpdateCheck::query()
            ->where('type', 'theme')
            ->where('update_available', true)
            ->count();

        Notification::make()
            ->title('Catalog refreshed — '.count($this->available).' theme'.(count($this->available) === 1 ? '' : 's').' in the marketplace.')
            ->body(match (true) {
                $updatesFound === 1 => 'One installed theme has an update.',
                $updatesFound > 1 => $updatesFound.' installed themes have updates.',
                default => null,
            })
            ->success()
            ->send();
    }

    private function loadCatalog(bool $fresh): void
    {
        $client = app(MarketplaceClient::class);

        if ($fresh) {
            $client->clearCache();
        }

        $catalog = $client->themes();

        $this->marketplaceUnreachable = $catalog === [] && $client->wasUnreachable();

        $this->available = array_map(fn (PluginListing $l): array => [
            'name' => $l->package,
            'display_name' => $l->name,
            'description' => $l->shortDescription,
            'author' => $l->author ?? '',
            'version' => $l->version,
            'icon' => $l->icon,
            'is_paid' => $l->isPaid(),
            'currency' => $l->currency,
            'prices' => $l->prices,
            'trial_enabled' => $l->trialEnabled && $l->isPaid(),
            'trial_days' => $l->trialDays,
        ], $catalog);
    }

    public function activate(string $name): void
    {
        try {
            $theme = $this->themes()->activate($name);
        } catch (Throwable $e) {
            Notification::make()->title('Could not activate that theme')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($theme->displayName.' is now the active theme.')->success()->send();
    }

    public function deactivate(): void
    {
        $this->themes()->deactivate();

        Notification::make()
            ->title('No theme is active')
            ->body('Anything reading the Delivery API will fall back to its own defaults.')
            ->success()
            ->send();
    }

    /**
     * Fetch and apply the newer version the check-in reported.
     *
     * A theme only ever arrives through a licence grant (free themes install
     * from the Magna Account too), so unlike PluginsPage::update() there is
     * no re-enable fallback: the licence is the single route to the bytes,
     * and LicenseInstaller::update() already does the in-place swap that
     * keeps the active theme active.
     */
    public function update(string $name): void
    {
        try {
            $message = app(LicenseInstaller::class)->update($name);
            Notification::make()->title($message)->success()->send();
        } catch (Throwable $e) {
            // Logged as well as shown, for the same reason as the Plugins
            // page: a toast leaves no server-side trace of a refused grant.
            Log::warning('Theme update failed.', [
                'theme' => $name,
                'reason' => $e->getMessage(),
            ]);

            Notification::make()->title('Update failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function remove(string $name): void
    {
        try {
            $this->themes()->remove($name);
        } catch (Throwable $e) {
            Notification::make()->title('Could not remove that theme')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Theme removed.')->success()->send();
    }

    // A Livewire page cannot constructor-inject, and the app() ratchet in
    // ArchitectureTest holds this file to a pinned count — one gateway for
    // the manager every action needs keeps that count flat.
    private function themes(): ThemeManager
    {
        return app(ThemeManager::class);
    }

    // buy(), startTrial(), onOrderSettled() and requireConnectedAccount()
    // live in ChecksOutWithRazorpay — this page only supplies its copy.

    protected function purchaseNeedsAccountBecause(): ?string
    {
        return 'A theme licence belongs to your Magna Account, so connect one before buying.';
    }

    protected function trialNeedsAccountBecause(): ?string
    {
        return 'A theme licence belongs to your Magna Account, so connect one before buying.';
    }
}
