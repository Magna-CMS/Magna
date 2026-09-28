<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Magna\Licensing\Concerns\ChecksOutWithRazorpay;
use Magna\Marketplace\MarketplaceClient;
use Magna\Marketplace\PluginListing;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeManifest;
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
        $this->refreshCatalog();
    }

    // The blade renders its own header, same as the Plugins page.
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $themes = app(ThemeManager::class);
        $installed = $themes->installed();
        $activeName = $themes->active()?->name;
        $activeAddonNames = array_map(
            fn (ThemeManifest $a): string => $a->name,
            $themes->activeAddons(),
        );

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
                ],
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
                ],
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
     * Pull the marketplace's theme catalog.
     *
     * `$fresh` is what the Refresh button passes and what a page load does
     * not. A catalog response stands for an hour, so without it the button
     * re-read the cache and showed exactly what was already on screen: a
     * theme published to the marketplace minutes ago stayed missing from
     * Browse, and there was no way to correct that short of waiting out the
     * TTL or clearing the cache from a shell. A page load still reads the
     * cache, because opening a screen is not a request to re-fetch.
     */
    public function refreshCatalog(bool $fresh = false): void
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
            $theme = app(ThemeManager::class)->activate($name);
        } catch (Throwable $e) {
            Notification::make()->title('Could not activate that theme')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($theme->displayName.' is now the active theme.')->success()->send();
    }

    public function deactivate(): void
    {
        app(ThemeManager::class)->deactivate();

        Notification::make()
            ->title('No theme is active')
            ->body('Anything reading the Delivery API will fall back to its own defaults.')
            ->success()
            ->send();
    }

    public function remove(string $name): void
    {
        try {
            app(ThemeManager::class)->remove($name);
        } catch (Throwable $e) {
            Notification::make()->title('Could not remove that theme')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Theme removed.')->success()->send();
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
