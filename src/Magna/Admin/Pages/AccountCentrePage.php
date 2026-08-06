<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Magna\AccountCentre\AccountCentreClient;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Licensing\Concerns\ChecksOutWithRazorpay;
use Magna\Licensing\LicenseClient;
use Magna\Licensing\LicenseStore;
use Magna\Updater\UpdateCheck;
use Throwable;

/**
 * This site's connection to a Magna Account (managemagna.jrstudios.dev) — not
 * a CMS admin login. Lives in the System nav group, directly below Plugins,
 * since connecting an account is what will gate plugin/theme installs later
 * (see docs/account-centre-plan.md).
 */
class AccountCentrePage extends Page
{
    // Renewing a term licence from the Licences card. Everything about
    // paying lives in the trait; onOrderSettled() below is this page's half.
    use ChecksOutWithRazorpay;

    protected static string|\BackedEnum|null $navigationIcon = 'magna-mark';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Magna Account';

    protected static ?string $title = 'Magna Account';

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'account-centre';

    protected string $view = 'magna::admin.account-centre';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $settings = AccountCentreSettings::get();

        $otherSites = [];
        if ($settings->connected && $settings->token !== null) {
            $otherSites = array_values(array_filter(
                app(AccountCentreClient::class)->sites($settings->token),
                fn (array $s): bool => ! ($s['is_this_site'] ?? false),
            ));
        }

        return [
            'connected' => $settings->connected,
            'accountName' => $settings->accountName,
            'accountEmail' => $settings->accountEmail,
            'connectedAt' => $settings->connectedAt,
            'otherSites' => $otherSites,
            'licenses' => $settings->connected ? $this->licenses() : [],
            'localLicenses' => app(LicenseStore::class)->all(),
            // Which installed products actually have a newer entitled version.
            // Without this the row offered "Update" for every licence active
            // here, and pressing it on an up-to-date plugin reported whatever
            // the installer had to say about re-downloading the same version.
            'productUpdates' => $this->availableProductUpdates(),
            'panel' => $settings->connected ? app(LicenseClient::class)->panel() : null,
            'invoices' => $settings->connected ? app(LicenseClient::class)->invoices() : [],
        ];
    }

    /**
     * Newer entitled versions of installed plugins, keyed by product slug.
     *
     * Read from the update check the site already performs (daily, and from
     * "Check for Updates"), so this page agrees with the Plugins page instead
     * of guessing. Licence-blocked versions are deliberately excluded: those
     * cannot be downloaded, and offering an Update button for them would fail
     * every time.
     *
     * @return array<string, string> slug => latest version
     */
    private function availableProductUpdates(): array
    {
        try {
            return UpdateCheck::query()
                ->where('type', 'plugin')
                ->where('update_available', true)
                ->whereNotNull('latest_version')
                ->pluck('latest_version', 'slug')
                ->all();
        } catch (Throwable) {
            // Never let the update hint break the account page.
            return [];
        }
    }

    /**
     * Buy another year on a licence this account already owns.
     *
     * The licence id is all that is sent: the term and the price come from
     * the licence itself on the marketplace, so nothing a browser can change
     * affects what is charged or what is extended.
     *
     * Gated on `licensing.manage` like every LicenseController action — the
     * page is readable on settings.view, but starting a checkout spends the
     * account's money and a Livewire call reaches this method whether or not
     * the button was rendered.
     */
    public function renew(int $licenseId): void
    {
        abort_unless(auth()->user()?->can('licensing.manage') ?? false, 403);

        $product = null;

        foreach ($this->licenses() as $licence) {
            if ((int) ($licence['id'] ?? 0) === $licenseId) {
                $product = is_string($licence['product_slug'] ?? null) ? $licence['product_slug'] : null;
                break;
            }
        }

        if ($product === null) {
            Notification::make()->title('That licence is no longer in your account.')->danger()->send();

            return;
        }

        $this->beginCheckout(app(LicenseClient::class)->renew($licenseId), $product);
    }

    /**
     * Stop an auto-debit mandate at the end of its paid period (§22 buyer
     * choice). Same gate as renew(): this changes what the account is
     * charged, so reading the page is not enough to reach it.
     */
    public function cancelAutoRenew(int $subscriptionId): void
    {
        abort_unless(auth()->user()?->can('licensing.manage') ?? false, 403);

        $result = app(LicenseClient::class)->cancelAutoRenew($subscriptionId);

        if (($result['ok'] ?? false) !== true) {
            Notification::make()
                ->title('Auto-renew could not be cancelled')
                ->body(is_string($result['message'] ?? null) ? $result['message'] : 'The marketplace refused the request. Try again shortly.')
                ->danger()
                ->send();

            return;
        }

        app(LicenseClient::class)->forgetCache();

        Notification::make()
            ->title('Auto-renew cancelled')
            ->body(is_string($result['message'] ?? null) ? $result['message'] : 'Your licence keeps working until the paid period ends.')
            ->success()
            ->send();
    }

    /**
     * The renewal went through. Nothing to install — the plugin is already
     * on the site — so this just drops the cached wallet and reloads, which
     * is what makes the new expiry date appear.
     */
    protected function onOrderSettled(int $licenseId, string $productSlug): void
    {
        Notification::make()
            ->title($productSlug.' renewed')
            ->body('Your licence has been extended and updates are entitled again.')
            ->success()
            ->send();

        $url = static::getUrl();
        $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 800)');
    }

    /**
     * The wallet, as the licence server sees it. Best-effort: an unreachable
     * server hides the Licences list rather than breaking the page — the
     * locally cached state (localLicenses) still renders, which is exactly
     * what an admin needs during an outage.
     *
     * @return list<array<string, mixed>>
     */
    private function licenses(): array
    {
        return app(LicenseClient::class)->wallet() ?? [];
    }
}
