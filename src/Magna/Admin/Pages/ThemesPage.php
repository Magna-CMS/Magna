<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Licensing\Concerns\ChecksOutWithRazorpay;
use Magna\Licensing\LicenseClient;
use Magna\Licensing\LicenseInstaller;
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
                array_values($installed),
            ),
            // Anything already on disk is not for sale again.
            'available' => array_values(array_filter(
                $this->available,
                fn (array $t): bool => ! isset($installed[$t['name']]),
            )),
            'activeName' => $activeName,
        ];
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['installed', 'browse'], true) ? $tab : 'installed';
    }

    /** Pull the marketplace's theme catalog. */
    public function refreshCatalog(): void
    {
        $client = app(MarketplaceClient::class);
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

    /** Buy a theme. Same order → gateway → webhook path as a plugin. */
    public function buy(string $package, string $term): void
    {
        if (! $this->requireConnectedAccount()) {
            return;
        }

        if (! in_array($term, ['lifetime', 'annual'], true)) {
            return;
        }

        $this->beginCheckout(app(LicenseClient::class)->checkout($package, $term), $package);
    }

    public function startTrial(string $package): void
    {
        if (! $this->requireConnectedAccount()) {
            return;
        }

        $client = app(LicenseClient::class);
        $result = $client->startTrial($package);

        if (($result['ok'] ?? false) !== true) {
            Notification::make()
                ->title('Trial could not be started')
                ->body($result['message'] ?? 'The marketplace refused this trial.')
                ->danger()
                ->send();

            return;
        }

        $client->forgetCache();

        foreach ($client->wallet() ?? [] as $licence) {
            if (($licence['product_slug'] ?? null) === $package && is_numeric($licence['id'] ?? null)) {
                $this->onOrderSettled((int) $licence['id'], $package);

                return;
            }
        }

        Notification::make()->title('Trial started')->body('Install it from the Magna Account page.')->success()->send();
    }

    /** The sale is real — fetch the theme and put it on disk. */
    protected function onOrderSettled(int $licenseId, string $productSlug): void
    {
        try {
            $message = app(LicenseInstaller::class)->installLicense($licenseId, $productSlug);
        } catch (Throwable $e) {
            Notification::make()
                ->title('Purchased — but the install did not finish')
                ->body($e->getMessage().' Your licence is safe; install it from the Magna Account page.')
                ->warning()
                ->send();

            return;
        }

        app(MarketplaceClient::class)->clearCache();

        Notification::make()->title($message)->success()->send();

        $url = static::getUrl();
        $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 800)');
    }

    private function requireConnectedAccount(): bool
    {
        if (AccountCentreSettings::get()->connected) {
            return true;
        }

        Notification::make()
            ->title('Connect your Magna Account first')
            ->body('A theme licence belongs to your Magna Account, so connect one before buying.')
            ->warning()
            ->send();

        return false;
    }
}
