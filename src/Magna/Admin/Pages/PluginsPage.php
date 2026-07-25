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
use Illuminate\Support\HtmlString;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Contracts\RegistersSettingsPages;
use Magna\Marketplace\InstallPluginJob;
use Magna\Marketplace\InstallState;
use Magna\Marketplace\MarketplaceClient;
use Magna\Marketplace\PluginInstaller;
use Magna\Marketplace\PluginListing;
use Magna\Plugins\Exceptions\PluginCompatibilityException;
use Magna\Plugins\PluginInfo;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Throwable;

class PluginsPage extends Page
{
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
            // Re-enable syncs the version from the manifest and re-runs any new migrations.
            app(PluginManager::class)->enable($name);
            Notification::make()->title('Plugin updated.')->success()->send();
            $url = static::getUrl();
            $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');
        } catch (Throwable $e) {
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
                // are processed one at a time by the installer's lock.
                InstallPluginJob::dispatch($package);

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
    private function requireConnectedAccount(): bool
    {
        if (AccountCentreSettings::get()->connected) {
            return true;
        }

        Notification::make()
            ->title('Connect your Magna Account first')
            ->body('Reviews and reports are tied to your Magna Account so they can be traced back to a real install.')
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

        foreach ($this->installQueue as $package) {
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

        $html .= <<<'HTML'
            <div class="mt-4 rounded-lg border border-warning-300 dark:border-warning-700 bg-warning-50 dark:bg-warning-950/30 px-4 py-3 flex gap-3">
                <svg class="w-5 h-5 text-warning-500 dark:text-warning-400 shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                </svg>
                <div class="text-sm text-warning-800 dark:text-warning-200">
                    <p class="font-semibold mb-0.5">Third-party plugin</p>
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
            ->modalDescription('The plugin record will be removed. Database tables are preserved.')
            ->modalSubmitActionLabel('Uninstall')
            ->color('danger')
            ->action(function (): void {
                try {
                    app(PluginManager::class)->uninstall($this->pendingPluginName ?? '');
                    Notification::make()->title('Plugin uninstalled.')->success()->send();
                    $url = static::getUrl();
                    $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');
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
            ->modalDescription('Removes the plugin record AND drops its database tables. This cannot be undone.')
            ->modalSubmitActionLabel('Delete everything')
            ->color('danger')
            ->action(function (): void {
                try {
                    app(PluginManager::class)->uninstall($this->pendingPluginName ?? '', purge: true);
                    Notification::make()->title('Plugin purged.')->success()->send();
                    $url = static::getUrl();
                    $this->js('setTimeout(function(){ window.location.replace('.json_encode($url).'); }, 400)');
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

        $this->installed = $records->map(function (PluginRecord $r) use ($discoveredVersions, $bootedPlugins): array {
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
                'version' => $r->version,
                'enabled' => $r->enabled,
                'description' => is_array($r->manifest) ? (string) ($r->manifest['description'] ?? '') : '',
                'author' => is_array($r->manifest) ? (string) ($r->manifest['author'] ?? '') : '',
                'source' => str_contains(str_replace('\\', '/', (string) $r->base_path), '/plugins-dev/')
                    ? 'plugins-dev/'
                    : 'Composer',
                'update_version' => isset($discoveredVersions[$r->name]) && $discoveredVersions[$r->name] !== $r->version
                    ? $discoveredVersions[$r->name]
                    : null,
                'settings_url' => $settingsUrl,
                // magna.json's optional "icon" field, served through PluginIconController;
                // null when the plugin declared none — the view falls back to a letter avatar.
                'icon_url' => is_string($icon) && $icon !== '' ? route('plugins.icon', explode('/', $r->name, 2)) : null,
            ];
        })->values()->all();

        // "Add New" is the marketplace: browse the official catalog (not yet installed).
        $marketplace = app(MarketplaceClient::class);
        $catalog = $marketplace->plugins();
        $this->marketplaceUnreachable = $catalog === [] && $marketplace->wasUnreachable();

        $this->available = collect($catalog)
            ->reject(fn (PluginListing $l): bool => in_array($l->package, $installedNames, true))
            ->map(fn (PluginListing $l): array => [
                'name' => $l->package,
                'display_name' => $l->name,
                'version' => $l->version,
                'description' => $l->shortDescription,
                'author' => $l->author ?? '',
                'source' => 'Marketplace',
                'icon' => $l->icon,
                'permissions' => $l->permissions,
                'rating' => $l->rating,
                'ratings_count' => $l->ratingsCount,
                'website' => $l->website,
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
