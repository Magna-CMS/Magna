<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Licensing\Concerns\ChecksOutWithRazorpay;
use Magna\Licensing\LicenseClient;
use Magna\Licensing\LicenseInstaller;
use Magna\Licensing\LicenseStore;
use Magna\Marketplace\InstallState;
use Magna\Marketplace\MarketplaceClient;
use Magna\Marketplace\PluginInstaller;
use Magna\Marketplace\PluginInstallStarter;
use Magna\Marketplace\PluginListing;
use Magna\Plugins\Exceptions\PluginCompatibilityException;
use Magna\Plugins\Exceptions\PluginNotFoundException;
use Magna\Plugins\PluginInfo;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Updater\UpdateCheck;
use Throwable;

class PluginsPage extends Page
{
    // Buying a paid plugin: open the order, show the gateway, wait for the
    // webhook. onOrderSettled() below is this page's half of it.
    use ChecksOutWithRazorpay;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Plugins';

    protected static ?int $navigationSort = 40;

    protected string $view = 'magna::admin.plugins';

    // ── Raw data ──────────────────────────────────────────────────────────────

    /** @var list<array<string, mixed>> */
    public array $installed = [];

    /** @var list<array<string, mixed>> */
    public array $available = [];

    /** True when the "Add New" tab's empty state is due to a failed marketplace fetch, not a genuinely empty catalog. */
    public bool $marketplaceUnreachable = false;

    // ── UI state ──────────────────────────────────────────────────────────────

    public string $activeTab = 'installed';

    public string $searchInstalled = '';

    public string $searchAvailable = '';

    public string $statusFilter = 'all';

    /** @var list<string> */
    public array $selectedPlugins = [];

    public string $bulkAction = '';

    // Confirmation target for modal actions
    public ?string $pendingPluginName = null;

    /** @var list<string> Packages currently queued/installing from the marketplace. */
    public array $installQueue = [];

    // The marketplace package a review/report modal is currently targeting.
    public string $feedbackPackage = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->refreshPlugins();
    }

    // Suppress Filament's default h1 — we render our own page header in the blade.
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    // ── View data (called by Filament on every Livewire render) ──────────────

    protected function getViewData(): array
    {
        $search = strtolower(trim($this->searchInstalled));

        $filteredInstalled = array_values(array_filter(
            $this->installed,
            function (array $p) use ($search): bool {
                $statusOk = match ($this->statusFilter) {
                    'active' => (bool) $p['enabled'],
                    'inactive' => ! (bool) $p['enabled'],
                    'update' => $p['update_version'] !== null,
                    default => true,
                };
                if (! $statusOk) {
                    return false;
                }
                if ($search === '') {
                    return true;
                }

                return str_contains(strtolower($p['display_name']), $search)
                    || str_contains(strtolower($p['description']), $search)
                    || str_contains(strtolower($p['author']), $search);
            }
        ));

        $searchAvail = strtolower(trim($this->searchAvailable));
        $filteredAvailable = $searchAvail === '' ? $this->available : array_values(array_filter(
            $this->available,
            fn (array $p): bool => str_contains(strtolower($p['display_name']), $searchAvail)
                || str_contains(strtolower($p['description']), $searchAvail)
                || str_contains(strtolower($p['author']), $searchAvail)
        ));

        $counts = [
            'all' => count($this->installed),
            'active' => count(array_filter($this->installed, fn ($p) => (bool) $p['enabled'])),
            'inactive' => count(array_filter($this->installed, fn ($p) => ! (bool) $p['enabled'])),
            'update' => count(array_filter($this->installed, fn ($p) => $p['update_version'] !== null)),
        ];

        $filteredNames = array_column($filteredInstalled, 'name');
        $allSelected = $filteredNames !== []
            && count(array_intersect($this->selectedPlugins, $filteredNames)) === count($filteredNames);

        $installProgress = [];
        foreach ($this->installQueue as $pkg) {
            $installProgress[$pkg] = PluginInstaller::progress($pkg);
        }

        return compact('filteredInstalled', 'filteredAvailable', 'counts', 'filteredNames', 'allSelected', 'installProgress');
    }

    // ── Tab / filter controls ─────────────────────────────────────────────────

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['installed', 'addnew'], true) ? $tab : 'installed';
        $this->selectedPlugins = [];
    }

    public function setStatusFilter(string $filter): void
    {
        $this->statusFilter = in_array($filter, ['all', 'active', 'inactive', 'update'], true) ? $filter : 'all';
        $this->selectedPlugins = [];
    }

    public function toggleSelectAll(): void
    {
        $filteredNames = $this->currentFilteredNames();
        $allSelected = $filteredNames !== []
            && count(array_intersect($this->selectedPlugins, $filteredNames)) === count($filteredNames);

        if ($allSelected) {
            $this->selectedPlugins = array_values(array_diff($this->selectedPlugins, $filteredNames));
        } else {
            $this->selectedPlugins = array_values(
                array_unique([...$this->selectedPlugins, ...$filteredNames])
            );
        }
    }

    public function applyBulkAction(): void
    {
        if ($this->bulkAction === '' || $this->selectedPlugins === []) {
            return;
        }

        $action = $this->bulkAction;
        $names = $this->selectedPlugins;
        $count = count($names);

        foreach ($names as $name) {
            try {
                match ($action) {
                    'activate' => app(PluginManager::class)->enable($name),
                    'deactivate' => app(PluginManager::class)->disable($name),
                    'delete' => app(PluginManager::class)->uninstall($name),
                    default => null,
                };
            } catch (Throwable) {
                // Continue with the rest even if one fails
            }
        }

        $label = match ($action) {
            'activate' => 'enabled',
            'deactivate' => 'disabled',
            'delete' => 'uninstalled',
            default => 'processed',
        };

        Notification::make()->title("{$count} plugin(s) {$label}.")->success()->send();
        $url = static::getUrl();
        $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');
    }

    // ── Direct (non-confirmatory) plugin actions ───────────────────────────────

    public function enable(string $name): void
    {
        try {
            app(PluginManager::class)->enable($name);
            Notification::make()->title('Plugin enabled.')->success()->send();
            $url = static::getUrl();
            $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');
        } catch (PluginCompatibilityException $e) {
            Notification::make()->title('Incompatible plugin')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Failed to enable plugin')->body($e->getMessage())->danger()->send();

            // A failed install rolls its record back, but this component still
            // renders the list from before — offering Enable/Uninstall for a
            // plugin that no longer exists. Re-query so the ghost row is gone
            // without the admin needing a hard refresh.
            $this->refreshPlugins();
        }
    }

    public function disable(string $name): void
    {
        try {
            app(PluginManager::class)->disable($name);
            Notification::make()->title('Plugin disabled.')->success()->send();
            $url = static::getUrl();
            $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');
        } catch (Throwable $e) {
            Notification::make()->title('Failed to disable plugin')->body($e->getMessage())->danger()->send();
        }
    }

    public function update(string $name): void
    {
        try {
            // A licensed plugin updates by fetching entitled bytes, not by
            // re-reading what is already on disk: its new version lives on
            // the marketplace and only the licence can authorise the
            // download. Re-enabling alone would report "updated" while
            // changing nothing — the failure mode this branch exists to
            // prevent.
            if (app(LicenseStore::class)->get($name) !== null) {
                $message = app(LicenseInstaller::class)->update($name);
                Notification::make()->title($message)->success()->send();
                $url = static::getUrl();
                $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');

                return;
            }

            // Re-enable syncs the version from the manifest and re-runs any new migrations.
            app(PluginManager::class)->enable($name);
            Notification::make()->title('Plugin updated.')->success()->send();
            $url = static::getUrl();
            $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');
        } catch (Throwable $e) {
            // Logged as well as shown. A toast is gone in six seconds and lives
            // only in the browser that saw it, so a failed update used to leave
            // no trace anywhere on the server — which is exactly the evidence
            // needed to work out why a licensed download was refused.
            Log::warning('Plugin update failed.', [
                'plugin' => $name,
                'reason' => $e->getMessage(),
            ]);

            Notification::make()->title('Update failed')->body($e->getMessage())->danger()->send();
        }
    }

    // ── Confirmation-gated actions ─────────────────────────────────────────────

    public function requestInstall(string $name): void
    {
        $this->pendingPluginName = $name;
        $this->mountAction('install');
    }

    public function requestUninstall(string $name): void
    {
        $this->pendingPluginName = $name;
        $this->mountAction('uninstall');
    }

    public function requestPurge(string $name): void
    {
        $this->pendingPluginName = $name;
        $this->mountAction('purge');
    }

    // ── Filament action definitions ────────────────────────────────────────────

    public function installAction(): Action
    {
        return Action::make('install')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-shield-check')
            ->modalHeading(fn (): string => 'Install '.$this->pendingDisplayName().'?')
            ->modalDescription(fn (): HtmlString => $this->installModalBody())
            ->modalSubmitActionLabel('Allow & install')
            ->modalCancelActionLabel('Cancel')
            ->color('primary')
            ->action(function (): void {
                $package = $this->pendingPluginName ?? '';
                $this->pendingPluginName = null;

                if ($package === '') {
                    return;
                }

                // Queue the install to run in the background; multiple installs
                // are processed one at a time by the installer's lock. The
                // starter also records the package, so pollInstalls() can run
                // the install itself if no queue worker ever takes the job.
                if (! app(PluginInstallStarter::class)->start($package)) {
                    Notification::make()
                        ->title("Can't install that")
                        ->body('That is not a valid vendor/package name.')
                        ->danger()
                        ->send();

                    return;
                }

                if (! in_array($package, $this->installQueue, true)) {
                    $this->installQueue[] = $package;
                }

                Notification::make()->title("Queued {$package} for installation…")->send();
            });
    }

    public function requestReview(string $name): void
    {
        if (! $this->requireConnectedAccount()) {
            return;
        }

        $this->feedbackPackage = $name;
        $this->mountAction('review');
    }

    public function requestReport(string $name): void
    {
        if (! $this->requireConnectedAccount()) {
            return;
        }

        $this->feedbackPackage = $name;
        $this->mountAction('report');
    }

    /**
     * Reviews and reports both require a connected Magna Account. Returns
     * whether the caller may proceed; when not connected, points the admin
     * at the Account Centre instead of opening the modal.
     */
    private function requireConnectedAccount(?string $because = null): bool
    {
        if (AccountCentreSettings::get()->connected) {
            return true;
        }

        Notification::make()
            ->title('Connect your Magna Account first')
            ->body($because ?? 'Reviews and reports are tied to your Magna Account so they can be traced back to a real install.')
            ->warning()
            ->actions([
                Action::make('connect')->label('Go to Magna Account')->url(AccountCentrePage::getUrl()),
            ])
            ->send();

        return false;
    }

    /** Write-a-review sheet: star rating + optional name and text, sent to the marketplace. */
    public function reviewAction(): Action
    {
        return Action::make('review')
            ->modalHeading(fn (): string => 'Review '.$this->feedbackDisplayName())
            ->modalIcon('heroicon-o-star')
            ->modalSubmitActionLabel('Submit review')
            ->form([
                Select::make('stars')
                    ->label('Your rating')
                    ->options([5 => '★★★★★', 4 => '★★★★☆', 3 => '★★★☆☆', 2 => '★★☆☆☆', 1 => '★☆☆☆☆'])
                    ->default(5)
                    ->required()
                    ->native(false),
                TextInput::make('author')->label('Your name')->maxLength(255)->placeholder('Optional'),
                Textarea::make('review')->label('Review')->rows(4)->maxLength(2000)->placeholder('What did you think of this plugin?'),
            ])
            ->action(function (array $data): void {
                $package = $this->feedbackPackage;
                $this->feedbackPackage = '';
                if ($package === '') {
                    return;
                }

                $ok = app(MarketplaceClient::class)->submitReview(
                    $package,
                    (int) $data['stars'],
                    $data['review'] ?? null,
                    $data['author'] ?? null,
                );

                $ok
                    ? Notification::make()->title('Thanks for your review!')->success()->send()
                    : Notification::make()->title("Couldn't submit your review")->body('The marketplace could not be reached. Please try again later.')->danger()->send();
            });
    }

    /** Report-a-plugin sheet: a reason + optional details, sent to the marketplace operators. */
    public function reportAction(): Action
    {
        return Action::make('report')
            ->modalHeading(fn (): string => 'Report '.$this->feedbackDisplayName())
            ->modalIcon('heroicon-o-flag')
            ->modalIconColor('danger')
            ->modalSubmitActionLabel('Submit report')
            ->color('danger')
            ->form([
                Select::make('reason')
                    ->label('Reason')
                    ->options([
                        'spam' => 'Spam or misleading',
                        'malicious' => 'Malicious or unsafe',
                        'broken' => "Doesn't work",
                        'copyright' => 'Copyright violation',
                        'other' => 'Other',
                    ])
                    ->required()
                    ->native(false),
                Textarea::make('details')->label('Details')->rows(3)->maxLength(2000)->placeholder('Optional — tell us what’s wrong.'),
            ])
            ->action(function (array $data): void {
                $package = $this->feedbackPackage;
                $this->feedbackPackage = '';
                if ($package === '') {
                    return;
                }

                $ok = app(MarketplaceClient::class)->reportPlugin(
                    $package,
                    (string) $data['reason'],
                    $data['details'] ?? null,
                );

                $ok
                    ? Notification::make()->title('Reported — thanks for flagging this.')->success()->send()
                    : Notification::make()->title("Couldn't submit the report")->body('The marketplace could not be reached. Please try again later.')->danger()->send();
            });
    }

    // ── Storefront ────────────────────────────────────────────────────────────

    /**
     * Open a checkout for a paid product.
     *
     * No price travels from here: the term is a name, the marketplace prices
     * it, and the amount that comes back is only used to render the gateway
     * window. This page never handles card data.
     */
    public function buy(string $package, string $term, bool $autoRenew = false): void
    {
        if (! $this->requireConnectedAccount('Buying a plugin needs a Magna Account — that is who the licence belongs to.')) {
            return;
        }

        if (! in_array($term, ['lifetime', 'annual'], true)) {
            return;
        }

        // Auto-renew is a property of the annual term only; the flag is
        // simply dropped for lifetime rather than refused, since the UI
        // never offers it there.
        $this->beginCheckout(
            app(LicenseClient::class)->checkout($package, $term, $autoRenew && $term === 'annual'),
            $package,
        );
    }

    /**
     * The sale is real — install what was bought.
     *
     * A failure here is reported as a failure to INSTALL, never as a failure
     * to buy: the licence is already in the account, and telling someone who
     * has just paid that something "failed" without that distinction is how
     * support tickets are made.
     */
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

    /**
     * Start a product's free trial straight from the catalog, then install
     * it — a trial someone has to go and find on another page is a trial
     * most people never start.
     */
    public function startTrial(string $package): void
    {
        if (! $this->requireConnectedAccount('A trial is issued to your Magna Account, so connect one first.')) {
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

        // The trial licence exists now, but its id is not in the reply — the
        // wallet is the one place that knows it, and reading it back also
        // proves the licence really landed.
        $licenseId = $this->walletLicenseIdFor($package);

        if ($licenseId === null) {
            Notification::make()
                ->title('Trial started')
                ->body('Install it from the Magna Account page.')
                ->success()
                ->send();

            return;
        }

        $this->onOrderSettled($licenseId, $package);
    }

    /** The wallet licence id for a product, or null when it is not there. */
    private function walletLicenseIdFor(string $package): ?int
    {
        foreach (app(LicenseClient::class)->wallet() ?? [] as $licence) {
            if (($licence['product_slug'] ?? null) === $package && is_numeric($licence['id'] ?? null)) {
                return (int) $licence['id'];
            }
        }

        return null;
    }

    private function feedbackDisplayName(): string
    {
        $plugin = collect($this->available)->firstWhere('name', $this->feedbackPackage);

        return is_array($plugin) ? (string) ($plugin['display_name'] ?? $this->feedbackPackage) : $this->feedbackPackage;
    }

    /** Poll install progress for the queued packages; notify + reload when done. */
    public function pollInstalls(): void
    {
        if ($this->installQueue === []) {
            return;
        }

        $stillGoing = [];
        $anyFinished = false;
        $starter = app(PluginInstallStarter::class);

        foreach ($this->installQueue as $package) {
            // No worker took the job. Run one stalled install per poll so a
            // long request stays bounded to a single Composer run; anything
            // else queued behind it is picked up by the following polls.
            if (! $anyFinished && $starter->isStalled($package)) {
                Notification::make()
                    ->title('No background worker is running')
                    ->body("Installing {$package} in this request instead — keep this page open until it finishes.")
                    ->warning()
                    ->send();

                $starter->installStalledInline($package);
            }

            $state = PluginInstaller::progress($package)['state'];

            if ($state === InstallState::Completed->value) {
                Notification::make()->title("{$package} installed.")->success()->send();
                $anyFinished = true;
            } elseif ($state === InstallState::Failed->value) {
                $message = PluginInstaller::progress($package)['message'];
                Notification::make()->title("Couldn't install {$package}")->body($message)->danger()->send();
                $anyFinished = true;
            } else {
                $stillGoing[] = $package;
            }
        }

        $this->installQueue = $stillGoing;

        // When everything finishes, reload so the installed list reflects reality.
        if ($anyFinished && $this->installQueue === []) {
            $url = static::getUrl();
            $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 600)');
        }
    }

    /**
     * Strips the leading "v" a version may carry, because the view adds its own.
     *
     * The two sources disagree: a manifest records `1.1.0` while the
     * marketplace records the Composer tag it was published under, `v1.1.0`.
     * The view renders "v{version}" either way, so a marketplace version came
     * out as "vv1.1.0". Normalising here rather than in the view keeps every
     * version this page hands out in one shape.
     */
    private static function displayVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        return ltrim($version, 'vV');
    }

    private function pendingDisplayName(): string
    {
        $plugin = collect($this->available)->firstWhere('name', $this->pendingPluginName ?? '');

        return is_array($plugin) ? (string) ($plugin['display_name'] ?? $this->pendingPluginName) : (string) $this->pendingPluginName;
    }

    /** Android-style install sheet: what it does, the permissions it wants, and a trust notice. */
    private function installModalBody(): HtmlString
    {
        $plugin = collect($this->available)->firstWhere('name', $this->pendingPluginName ?? '');
        $permissions = is_array($plugin) && is_array($plugin['permissions'] ?? null) ? $plugin['permissions'] : [];
        $official = is_array($plugin) && ($plugin['official'] ?? false) === true;
        $verified = is_array($plugin) && ($plugin['verified'] ?? false) === true;

        $html = '<p class="text-sm text-gray-600 dark:text-gray-300">This plugin will be downloaded from the marketplace, then enabled on your site.</p>';

        $html .= '<div class="mt-4"><p class="text-sm font-semibold text-gray-900 dark:text-white mb-1.5">Permissions requested</p>';
        if ($permissions !== []) {
            $items = '';
            foreach ($permissions as $permission) {
                $items .= '<li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">'
                    .'<svg class="w-4 h-4 text-primary-500 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>'
                    .'<code class="font-mono">'.e((string) $permission).'</code></li>';
            }
            $html .= '<ul class="space-y-1.5">'.$items.'</ul>';
        } else {
            $html .= '<p class="text-sm text-gray-500 dark:text-gray-400">No special permissions requested.</p>';
        }
        $html .= '</div>';

        // The trust notice matches what the marketplace actually asserts about
        // the publisher. Official = the registry itself vouches for the
        // developer account (badge granted by the operator, never inferred
        // from the vendor prefix) — warning it against itself just teaches
        // admins to ignore the real warning. Everything else keeps the full
        // third-party caution, with the "identity checked" nuance for
        // verified publishers.
        if ($official) {
            $html .= <<<'HTML'
                <div class="mt-4 rounded-lg border border-success-300 dark:border-success-700 bg-success-50 dark:bg-success-950/30 px-4 py-3 flex gap-3">
                    <svg class="w-5 h-5 text-success-500 dark:text-success-400 shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M16.403 12.652a3 3 0 000-5.304 3 3 0 00-3.75-3.751 3 3 0 00-5.305 0 3 3 0 00-3.751 3.75 3 3 0 000 5.305 3 3 0 003.75 3.751 3 3 0 005.305 0 3 3 0 003.751-3.75zm-2.546-4.46a.75.75 0 00-1.214-.883l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
                    </svg>
                    <div class="text-sm text-success-800 dark:text-success-200">
                        <p class="font-semibold mb-0.5">Official plugin</p>
                        <p class="text-success-700 dark:text-success-300">Published by the marketplace's own team. Like every plugin it runs with full application access.</p>
                    </div>
                </div>
            HTML;

            return new HtmlString($html);
        }

        $heading = $verified ? 'Third-party plugin — verified publisher' : 'Third-party plugin';

        $html .= <<<HTML
            <div class="mt-4 rounded-lg border border-warning-300 dark:border-warning-700 bg-warning-50 dark:bg-warning-950/30 px-4 py-3 flex gap-3">
                <svg class="w-5 h-5 text-warning-500 dark:text-warning-400 shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                </svg>
                <div class="text-sm text-warning-800 dark:text-warning-200">
                    <p class="font-semibold mb-0.5">{$heading}</p>
                    <p class="text-warning-700 dark:text-warning-300">Once enabled it runs with <strong>full application access</strong> — it can read your database, files, and environment. Only install plugins you trust.</p>
                </div>
            </div>
        HTML;

        return new HtmlString($html);
    }

    public function uninstallAction(): Action
    {
        return Action::make('uninstall')
            ->requiresConfirmation()
            ->modalHeading('Uninstall plugin')
            ->modalDescription('The plugin files are deleted from this site and its Composer entry removed. Database tables and their data are preserved — reinstalling the plugin picks them up again.')
            ->modalSubmitActionLabel('Uninstall')
            ->color('danger')
            ->action(function (): void {
                try {
                    app(PluginManager::class)->uninstall($this->pendingPluginName ?? '', removeFiles: true);
                    Notification::make()->title('Plugin uninstalled.')->success()->send();
                    $url = static::getUrl();
                    $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');
                } catch (PluginNotFoundException) {
                    // The record is already gone — typically a failed install
                    // that rolled itself back while this page still showed the
                    // row. The outcome the admin wanted is already true; the
                    // "run composer require" wording would only mislead here.
                    Notification::make()->title('Plugin already removed — refreshing the list.')->success()->send();
                    $this->refreshPlugins();
                } catch (Throwable $e) {
                    Notification::make()->title('Uninstall failed')->body($e->getMessage())->danger()->send();
                } finally {
                    $this->pendingPluginName = null;
                }
            });
    }

    public function purgeAction(): Action
    {
        return Action::make('purge')
            ->requiresConfirmation()
            ->modalHeading('Purge plugin data')
            ->modalDescription('Deletes the plugin files AND drops every database table it created, with all their data. This cannot be undone.')
            ->modalSubmitActionLabel('Delete everything')
            ->color('danger')
            ->action(function (): void {
                try {
                    app(PluginManager::class)->uninstall($this->pendingPluginName ?? '', purge: true, removeFiles: true);
                    Notification::make()->title('Plugin purged.')->success()->send();
                    $url = static::getUrl();
                    $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');
                } catch (PluginNotFoundException) {
                    // See uninstallAction(): the row was stale, nothing to purge.
                    Notification::make()->title('Plugin already removed — refreshing the list.')->success()->send();
                    $this->refreshPlugins();
                } catch (Throwable $e) {
                    Notification::make()->title('Purge failed')->body($e->getMessage())->danger()->send();
                } finally {
                    $this->pendingPluginName = null;
                }
            });
    }

    // ── Data refresh ──────────────────────────────────────────────────────────

    public function refreshPlugins(): void
    {
        // Surface any pre-bundled (vendor/) plugins that were never installed
        // through the marketplace/zip flow, so they appear here and can be
        // enabled. Idempotent; only creates missing rows, always disabled.
        app(PluginManager::class)->syncDiscovered();

        $records = PluginRecord::query()->orderBy('display_name')->get();
        $installedNames = $records->pluck('name')->all();

        // Map discovered plugin versions for update detection
        $discoveredVersions = collect(app(PluginManager::class)->discover())
            ->keyBy(fn (PluginInfo $info): string => $info->manifest->name)
            ->map(fn (PluginInfo $info): string => $info->manifest->version)
            ->all();

        $bootedPlugins = app(PluginManager::class)->getEnabled();

        // Updates a licence no longer covers. The marketplace deliberately
        // reports these as NOT available (the download would be refused), so
        // without this the admin would simply never hear that a newer version
        // exists — the worst way to learn a licence lapsed.
        $licenseBlocked = UpdateCheck::query()
            ->where('type', 'plugin')
            ->where('license_required', true)
            ->pluck('latest_version', 'slug')
            ->all();

        // Versions published to the marketplace since this site installed.
        // Update detection used to compare the on-disk manifest against the
        // plugins row, which only ever notices files someone had ALREADY put
        // there — so publishing a new version of a paid plugin left this page
        // reading "Update Available (0)" while `magna:updater:check` was
        // reporting the very same update.
        $marketplaceUpdates = UpdateCheck::query()
            ->where('type', 'plugin')
            ->where('update_available', true)
            ->whereNotNull('latest_version')
            ->pluck('latest_version', 'slug')
            ->all();

        // The catalog is read before the installed list is built: publisher
        // trust ("official") is something only the marketplace knows, so an
        // installed plugin's badge has to come from the same listing the
        // marketplace serves, keyed by package name.
        $marketplace = app(MarketplaceClient::class);
        $catalog = $marketplace->plugins();
        $this->marketplaceUnreachable = $catalog === [] && $marketplace->wasUnreachable();

        /** @var array<string, PluginListing> $listingsByPackage */
        $listingsByPackage = collect($catalog)->keyBy(fn (PluginListing $l): string => $l->package)->all();

        $this->installed = $records->map(function (PluginRecord $r) use ($discoveredVersions, $marketplaceUpdates, $bootedPlugins, $listingsByPackage): array {
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

            $icon = is_array($r->manifest) ? ($r->manifest['icon'] ?? null) : null;

            return [
                'name' => $r->name,
                'display_name' => $r->display_name,
                'version' => self::displayVersion($r->version),
                'enabled' => $r->enabled,
                'description' => is_array($r->manifest) ? (string) ($r->manifest['description'] ?? '') : '',
                'author' => is_array($r->manifest) ? (string) ($r->manifest['author'] ?? '') : '',
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
        })->values()->all();

        // "Add New" is the marketplace: browse the official catalog (not yet installed).
        $this->available = collect($catalog)
            ->reject(fn (PluginListing $l): bool => in_array($l->package, $installedNames, true))
            ->map(fn (PluginListing $l): array => [
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
            ])->values()->all();
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /** Returns the names of plugins currently visible in the filtered installed table. */
    private function currentFilteredNames(): array
    {
        $q = strtolower(trim($this->searchInstalled));

        return array_column(
            array_filter($this->installed, function (array $p) use ($q): bool {
                $statusOk = match ($this->statusFilter) {
                    'active' => (bool) $p['enabled'],
                    'inactive' => ! (bool) $p['enabled'],
                    'update' => $p['update_version'] !== null,
                    default => true,
                };

                if (! $statusOk || $q === '') {
                    return $statusOk;
                }

                return str_contains(strtolower($p['display_name']), $q)
                    || str_contains(strtolower($p['description']), $q)
                    || str_contains(strtolower($p['author']), $q);
            }),
            'name'
        );
    }
}
