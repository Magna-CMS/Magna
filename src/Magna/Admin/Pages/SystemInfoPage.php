<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\HtmlString;
use Laravel\Octane\OctaneServiceProvider;
use Livewire\Attributes\Locked;
use Magna\MagnaServiceProvider;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\System\SystemHealthCollector;
use Magna\Updater\CoreUpdateProgress;
use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateStarter;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\IncompatiblePlugin;
use Magna\Updater\PendingCoreUpdate;
use Magna\Updater\UpdateCheck;
use Magna\Updater\UpdateCheckClient;
use Throwable;

class SystemInfoPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-information-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System Info';

    protected static ?string $title = 'System Insights';

    protected static ?int $navigationSort = 99;

    protected string $view = 'magna::admin.system-info';

    /** @var list<array{type: string, text: string}> */
    public array $terminalLines = [];

    /** True while an "Update Now" apply is in progress, so the page polls CoreUpdater::progress(). */
    public bool $updating = false;

    /**
     * Enabled plugins found incompatible with the pending target version,
     * captured by updateNow's pre-flight check for the resolution modal.
     *
     * @var list<array{name: string, displayName: string, installedVersion: string, requiredCompat: string}>
     */
    #[Locked]
    public array $incompatiblePlugins = [];

    /**
     * Target version captured while the resolveIncompatiblePlugins modal is
     * open.
     *
     * #[Locked] because a Livewire public property round-trips through the
     * browser: without it the client could rewrite the pending target between
     * opening the modal and submitting it. The URL and checksum are no longer
     * held here at all — they are re-read from the update_checks row at
     * dispatch time, so the only thing the browser can influence is *which*
     * already-recorded release is applied, and even that is verified.
     */
    #[Locked]
    public ?string $pendingUpdateVersion = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.view') ?? false;
    }

    /**
     * Nav badge showing pending core + plugin updates, sourced from the last
     * check-in (scheduled every 12h, or on-demand via the button below) —
     * never queries Update Manager directly from a page render.
     */
    public static function getNavigationBadge(): ?string
    {
        $total = UpdateCheck::totalAvailable();

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkForUpdates')
                ->label('Check for Updates')
                ->icon('heroicon-o-arrow-down-circle')
                ->color('primary')
                ->action(function (UpdateCheckClient $client): void {
                    $result = $client->checkIn();
                    $current = MagnaServiceProvider::VERSION;

                    if ($result === null) {
                        Notification::make()
                            ->title('Could not reach Update Manager')
                            ->body("Running v{$current}. The update service didn't respond — try again shortly.")
                            ->warning()
                            ->send();

                        return;
                    }

                    $pluginUpdates = UpdateCheck::pluginsWithUpdates()->count();

                    if ($result->core?->updateAvailable) {
                        Notification::make()
                            ->title('Update available: v'.$result->core->latestVersion)
                            ->body("Running v{$current}.".($pluginUpdates > 0 ? " {$pluginUpdates} plugin update(s) also available." : ''))
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Magna CMS is up to date')
                        ->body("Running v{$current}.".($pluginUpdates > 0 ? " {$pluginUpdates} plugin update(s) available." : ' No newer release found.'))
                        ->success()
                        ->send();
                }),

            Action::make('updateNow')
                ->label(fn (): string => 'Update to v'.(UpdateCheck::core()?->latest_version ?? ''))
                ->icon('heroicon-o-rocket-launch')
                ->color('warning')
                ->visible(fn (): bool => (UpdateCheck::core()?->update_available ?? false) && ! $this->updating)
                ->authorize(fn (): bool => auth()->user()?->can('settings.manage') ?? false)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => 'Update Magna CMS to v'.(UpdateCheck::core()?->latest_version ?? '').'?')
                ->modalDescription('The site goes into maintenance mode during the update. Current files are backed up first and restored automatically if anything fails.')
                ->modalSubmitActionLabel('Update now')
                ->action(function (CoreUpdater $updater, CoreUpdateStarter $starter): void {
                    $core = UpdateCheck::core();
                    if ($core?->latest_version === null || $core->download_url === null || $core->download_sha256 === null) {
                        Notification::make()->title("Can't update")->body('No verified release archive is available for the latest version yet.')->danger()->send();

                        return;
                    }

                    $incompatible = $updater->checkCompatibility($core->latest_version);
                    if ($incompatible !== []) {
                        $this->pendingUpdateVersion = $core->latest_version;
                        $this->incompatiblePlugins = array_map(
                            static fn (IncompatiblePlugin $p): array => $p->toArray(),
                            $incompatible,
                        );
                        $this->replaceMountedAction('resolveIncompatiblePlugins');

                        return;
                    }

                    $starter->start(new PendingCoreUpdate(
                        version: $core->latest_version,
                        zipUrl: $core->download_url,
                        expectedSha256: $core->download_sha256,
                        checksumSignature: $core->download_sha256_signature,
                    ));
                    $this->updating = true;
                    Notification::make()->title('Update started…')->send();
                }),
        ];
    }

    /**
     * Shown when updateNow finds enabled plugins incompatible with the target
     * core version. The primary submit forces the update anyway (incompatible
     * plugins are auto-disabled by CoreUpdater once the new core is in place);
     * the extra footer action uninstalls them first and updates cleanly;
     * cancelling (Filament's default) leaves everything untouched.
     */
    public function resolveIncompatiblePluginsAction(): Action
    {
        return Action::make('resolveIncompatiblePlugins')
            ->authorize(fn (): bool => auth()->user()?->can('settings.manage') ?? false)
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->modalHeading(fn (): string => count($this->incompatiblePlugins).' plugin(s) are incompatible with v'.($this->pendingUpdateVersion ?? ''))
            ->modalDescription(fn (): HtmlString => $this->incompatiblePluginsModalBody())
            ->modalSubmitActionLabel('Force update anyway')
            ->modalCancelActionLabel('Cancel')
            ->color('danger')
            ->extraModalFooterActions([
                Action::make('uninstallIncompatibleAndContinue')
                    ->label('Uninstall these plugins & continue')
                    ->color('warning')
                    ->authorize(fn (): bool => auth()->user()?->can('settings.manage') ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Uninstall incompatible plugins?')
                    ->modalDescription('Each plugin listed is uninstalled (data tables are preserved; you can reinstall a compatible version later) and the update then proceeds normally.')
                    ->modalSubmitActionLabel('Uninstall & update')
                    ->action(fn () => $this->uninstallIncompatibleAndContinue()),
            ])
            ->action(fn () => $this->forceUpdateAnyway());
    }

    private function incompatiblePluginsModalBody(): HtmlString
    {
        $rows = '';
        foreach ($this->incompatiblePlugins as $plugin) {
            $rows .= '<tr>'
                .'<td class="py-1 pr-4 font-medium">'.e($plugin['displayName']).'</td>'
                .'<td class="py-1 pr-4 text-gray-500 dark:text-gray-400">v'.e($plugin['installedVersion']).'</td>'
                .'<td class="py-1 text-gray-500 dark:text-gray-400">requires magna '.e($plugin['requiredCompat']).'</td>'
                .'</tr>';
        }

        $html = '<div class="space-y-3">'
            .'<p class="text-sm">These enabled plugins declare they don\'t support v'.e($this->pendingUpdateVersion ?? '').'. '
            .'Forcing the update anyway will automatically disable them once the new core is in place (their data and settings are preserved).</p>'
            .'<table class="w-full text-sm"><tbody>'.$rows.'</tbody></table>'
            .'</div>';

        return new HtmlString($html);
    }

    /** Primary submit of resolveIncompatiblePlugins: proceed despite the conflicts. */
    private function forceUpdateAnyway(): void
    {
        $target = $this->pendingReleaseTarget();
        $this->incompatiblePlugins = [];
        $this->pendingUpdateVersion = null;

        if ($target === null) {
            return;
        }

        [$version, $zipUrl, $sha256, $signature] = $target;

        app(CoreUpdateStarter::class)->start(new PendingCoreUpdate($version, $zipUrl, $sha256, force: true, checksumSignature: $signature));
        $this->updating = true;
        Notification::make()
            ->title('Forced update started…')
            ->body('Incompatible plugins will be automatically disabled once the update finishes.')
            ->warning()
            ->send();
    }

    /** Extra footer action of resolveIncompatiblePlugins: remove the conflicting plugins, then update. */
    private function uninstallIncompatibleAndContinue(): void
    {
        $target = $this->pendingReleaseTarget();
        $names = array_column($this->incompatiblePlugins, 'name');
        $this->incompatiblePlugins = [];
        $this->pendingUpdateVersion = null;

        if ($target === null) {
            return;
        }

        [$version, $zipUrl, $sha256, $signature] = $target;

        $manager = app(PluginManager::class);
        $failed = [];
        foreach ($names as $name) {
            try {
                $manager->uninstall($name);
            } catch (Throwable) {
                $failed[] = $name;
            }
        }

        if ($failed !== []) {
            Notification::make()
                ->title("Couldn't uninstall: ".implode(', ', $failed))
                ->body('The update was not started. Resolve this manually (Plugins page) and try again.')
                ->danger()
                ->send();

            return;
        }

        // Re-verify rather than trusting the uninstalls succeeded silently — the
        // update job enforces this too, but a clean recheck here gives an
        // accurate notification instead of a job that queues then fails later.
        $stillIncompatible = app(CoreUpdater::class)->checkCompatibility($version);
        if ($stillIncompatible !== []) {
            $names = array_map(static fn (IncompatiblePlugin $p): string => $p->displayName, $stillIncompatible);
            Notification::make()
                ->title('Still incompatible: '.implode(', ', $names))
                ->body('The update was not started.')
                ->danger()
                ->send();

            return;
        }

        app(CoreUpdateStarter::class)->start(new PendingCoreUpdate($version, $zipUrl, $sha256, checksumSignature: $signature));
        $this->updating = true;
        Notification::make()->title('Plugins removed. Update started…')->send();
    }

    /**
     * Resolve the release the modal is about, from the recorded update check
     * rather than from anything the browser sent back.
     *
     * The archive URL and its checksum are what CoreUpdater overlays onto
     * `app/`, `bootstrap/`, and `src/Magna` — code that runs on every
     * subsequent request. They are therefore never carried in component state:
     * they are read here, at dispatch, from the row the scheduled check-in
     * wrote, and only after confirming it still describes the version the
     * admin was shown.
     *
     * @return array{0: string, 1: string, 2: string, 3: string|null}|null
     */
    private function pendingReleaseTarget(): ?array
    {
        $version = $this->pendingUpdateVersion;

        if ($version === null) {
            return null;
        }

        $core = UpdateCheck::core();

        if (
            $core?->latest_version !== $version
            || ! is_string($core->download_url)
            || ! is_string($core->download_sha256)
        ) {
            Notification::make()
                ->title("Can't update")
                ->body('The recorded release for v'.$version.' has changed or is missing its verified checksum. Re-check for updates and try again.')
                ->danger()
                ->send();

            return null;
        }

        return [$version, $core->download_url, $core->download_sha256, $core->download_sha256_signature];
    }

    /** Poll the running core update; notify + reload the page when it finishes. */
    public function pollCoreUpdate(): void
    {
        if (! $this->updating) {
            return;
        }

        $starter = app(CoreUpdateStarter::class);

        // Reading progress is fine for anyone who can see this page
        // (settings.view), but the stall fallback *performs* the apply inside
        // this request — that is the same privilege "Update Now" needs, and
        // $updating is a plain public property a client can flip. So the
        // fallback is authorized separately here rather than inherited from
        // page access.
        if ($starter->isStalled() && (auth()->user()?->can('settings.manage') ?? false)) {
            $this->applyStalledUpdate($starter);

            return;
        }

        $this->reportUpdateOutcome(CoreUpdater::progress());
    }

    /**
     * No worker took the job, so this request applies the update itself.
     *
     * Deliberately synchronous: the admin is already watching a progress panel,
     * the site is in maintenance mode for the duration, and the alternative is
     * an update that never runs at all.
     */
    private function applyStalledUpdate(CoreUpdateStarter $starter): void
    {
        Notification::make()
            ->title('No background worker is running')
            ->body('Applying the update in this request instead — keep this page open until it finishes.')
            ->warning()
            ->send();

        if ($starter->applyStalledInline() === null) {
            return;
        }

        $this->reportUpdateOutcome(CoreUpdater::progress());
    }

    /** @param  array{state: string|null, message: string, percent: int, version: string|null, log: list<array{message: string, percent: int}>, waiting_seconds: int}  $progress */
    private function reportUpdateOutcome(array $progress): void
    {
        if ($progress['state'] === CoreUpdateState::Completed->value) {
            $this->updating = false;
            CoreUpdateProgress::clearPending();
            Notification::make()->title('Update complete')->body($progress['message'])->success()->send();
            $this->js('setTimeout(function(){ window.location.reload(); }, 800)');
        } elseif ($progress['state'] === CoreUpdateState::Failed->value) {
            $this->updating = false;
            CoreUpdateProgress::clearPending();
            Notification::make()->title("Update didn't complete")->body($progress['message'])->danger()->send();
        }
    }

    /**
     * Writing APP_DEBUG=true to .env turns stack traces, SQL and environment
     * dumps on for every visitor, and clearing the cache is a site-wide
     * side effect — neither is a read-only operation, so neither may be
     * reachable with the settings.view that canAccess() asks for. Livewire
     * methods are callable by anyone who can render the component, regardless
     * of whether the button that calls them was rendered, so the check has to
     * live in the method.
     */
    private function authorizeSettingsManage(): void
    {
        abort_unless(auth()->user()?->can('settings.manage') ?? false, 403);
    }

    public function toggleDebugMode(): void
    {
        $this->authorizeSettingsManage();

        $newValue = ! (bool) config('app.debug');
        $envPath = base_path('.env');

        if (! file_exists($envPath) || ! is_writable($envPath)) {
            Notification::make()
                ->title('Cannot update .env')
                ->body('The .env file is missing or not writable by the web server.')
                ->danger()
                ->send();

            return;
        }

        $content = (string) file_get_contents($envPath);
        $newLine = 'APP_DEBUG='.($newValue ? 'true' : 'false');

        if (preg_match('/^APP_DEBUG=/m', $content) === 1) {
            $content = (string) preg_replace('/^APP_DEBUG=.*/m', $newLine, $content);
        } else {
            $content .= "\n".$newLine;
        }

        file_put_contents($envPath, $content);

        // Do NOT mutate config('app.debug') in-request: Livewire only records
        // its render-timing $start when debug is on at the *start* of the
        // request, so flipping it mid-request triggers "Undefined variable
        // $start". Instead, clear any cached config and reload so the new .env
        // value takes effect on a fresh request.
        Artisan::call('config:clear');

        Notification::make()
            ->title('Debug mode '.($newValue ? 'enabled' : 'disabled'))
            ->body('APP_DEBUG updated in .env.')
            ->success()
            ->send();

        $this->redirect(static::getUrl(), navigate: false);
    }

    public function runDiagnostics(): void
    {
        $data = $this->getViewData();

        $this->terminalLines[] = ['type' => 'cmd',     'text' => 'php artisan magna:diagnostics --detailed'];
        $this->terminalLines[] = ['type' => 'init',    'text' => '[INIT] Starting general diagnostics sequence on '.$data['db_driver'].' storage node...'];
        $this->terminalLines[] = ['type' => 'info',    'text' => '[INFO] PHP engine: '.$data['php_version']];
        $this->terminalLines[] = ['type' => 'info',    'text' => '[INFO] Laravel framework: '.$data['laravel_version']];
        $this->terminalLines[] = ['type' => 'info',    'text' => '[INFO] Database: '.$data['db_driver'].' v'.$data['db_version']];
        $this->terminalLines[] = ['type' => 'info',    'text' => "[INFO] Cache driver: '{$data['cache_driver']}' — status: ".strtoupper((string) $data['cache_status'])];
        $this->terminalLines[] = ['type' => 'info',    'text' => '[INFO] Queue: '.$data['queue_connection'].' | Storage: '.$data['storage_disk']];
        $this->terminalLines[] = ['type' => 'info',    'text' => '[INFO] Octane: '.($data['octane_installed'] ? ($data['octane_running'] ? 'RUNNING ('.$data['octane_server'].')' : 'installed, not running (plain PHP-FPM/CLI request)') : 'not installed')];
        $this->terminalLines[] = ['type' => 'info',    'text' => '[INFO] Plugins installed: '.$data['plugins_total'].' ('.$data['plugins_enabled'].' enabled)'];

        if ($data['cache_status'] === 'ok') {
            $this->terminalLines[] = ['type' => 'success', 'text' => '[SUCCESS] Diagnostic sequence complete. All system connections working normally.'];
            Notification::make()->title('Diagnostics complete')->body('All environment nodes checked successfully.')->success()->send();
        } else {
            $this->terminalLines[] = ['type' => 'error', 'text' => '[ERROR] Cache connection failed. Check your cache driver configuration.'];
            Notification::make()->title('Diagnostics warning')->body('Cache connection issue detected.')->warning()->send();
        }
    }

    public function clearCache(): void
    {
        $this->authorizeSettingsManage();

        $driver = (string) config('cache.default', 'file');
        $this->terminalLines[] = ['type' => 'cmd',  'text' => 'php artisan cache:clear'];
        $this->terminalLines[] = ['type' => 'info', 'text' => "[INFO] Clearing internal storage caches on driver '{$driver}'..."];

        try {
            Artisan::call('cache:clear');
            $output = trim(Artisan::output()) ?: 'Application cache cleared successfully.';
            $this->terminalLines[] = ['type' => 'success', 'text' => '[SUCCESS] '.$output];
            Notification::make()->title('Cache cleared')->success()->send();
        } catch (Throwable $e) {
            $this->terminalLines[] = ['type' => 'error', 'text' => '[ERROR] '.$e->getMessage()];
            Notification::make()->title('Cache clear failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function clearTerminal(): void
    {
        $this->terminalLines = [];
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $pluginsEnabled = PluginRecord::query()->where('enabled', true)->count();
        $pluginsTotal = PluginRecord::query()->count();

        $health = app(SystemHealthCollector::class);

        return [
            'magna_version' => MagnaServiceProvider::VERSION,
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'db_driver' => $health->dbDriver(),
            'db_version' => $health->dbVersion(),
            'environment' => app()->environment(),
            'debug_mode' => (bool) config('app.debug'),
            'cache_driver' => (string) config('cache.default', 'file'),
            'queue_connection' => (string) config('queue.default', 'sync'),
            'storage_disk' => (string) config('filesystems.default', 'local'),
            'plugins_total' => $pluginsTotal,
            'plugins_enabled' => $pluginsEnabled,
            'plugins_disabled' => $pluginsTotal - $pluginsEnabled,
            'cache_status' => $health->cacheStatus(),
            'app_url' => parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST) ?? config('app.url', 'localhost'),
            'session_lifetime' => (int) config('session.lifetime', 120),
            'octane_installed' => class_exists(OctaneServiceProvider::class),
            'octane_running' => filter_var(getenv('LARAVEL_OCTANE'), FILTER_VALIDATE_BOOLEAN),
            'octane_server' => (string) config('octane.server', 'frankenphp'),
            'performance_warnings' => $health->performanceWarnings(),
            'security_warnings' => $health->securityWarnings(),
            'boot_time_ms' => $health->bootTimeMs(),
            'memory_current_mb' => round(memory_get_usage(true) / 1_048_576, 1),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1_048_576, 1),
            'cache_latency_ms' => $health->cacheLatencyMs(),
            'opcache' => $health->opcacheStatus(),
            'queue_pending' => $health->queuePendingCount(),
            'queue_failed' => $health->queueFailedCount(),
            // Age of the oldest waiting job — the difference between "busy"
            // and "nothing is running this queue".
            'queue_oldest_minutes' => $health->queueOldestPendingMinutes(),
            'backup_health' => $health->backupHealth(),
        ];
    }
}
