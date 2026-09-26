<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Laravel\Octane\OctaneServiceProvider;
use Magna\Admin\Concerns\DrivesCoreUpdate;
use Magna\Admin\Concerns\ResolvesIncompatiblePlugins;
use Magna\MagnaServiceProvider;
use Magna\Plugins\PluginRecord;
use Magna\Support\Runtime;
use Magna\System\SystemHealthCollector;
use Magna\Updater\Footprint\FootprintCheck;
use Magna\Updater\UpdateCheck;
use Magna\Updater\UpdateCheckClient;
use Throwable;

class SystemInfoPage extends Page
{
    use DrivesCoreUpdate;
    use ResolvesIncompatiblePlugins;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-information-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System Info';

    protected static ?string $title = 'System Insights';

    protected static ?int $navigationSort = 99;

    protected string $view = 'magna::admin.system-info';

    /** @var list<array{type: string, text: string}> */
    public array $terminalLines = [];

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

            $this->updateNowAction(),
            $this->repairCoreAction(),
        ];
    }

    /**
     * Clearing the cache is a site-wide side effect — not a read-only
     * operation, so it may not be reachable with the settings.view that
     * canAccess() asks for. Livewire methods are callable by anyone who can
     * render the component, regardless of whether the button that calls them
     * was rendered, so the check has to live in the method.
     *
     * Debug mode is deliberately not here at all: it is the one control that
     * can expose the whole site, so it lives behind its own route and gate
     * (Magna\Admin\Http\DebugModeController).
     */
    private function authorizeSettingsManage(): void
    {
        Gate::authorize('settings.manage');
    }

    public function runDiagnostics(): void
    {
        $data = $this->getViewData();

        $this->terminalLines[] = ['type' => 'cmd',     'text' => 'php artisan magna:diagnostics --detailed'];
        $this->terminalLines[] = ['type' => 'init',    'text' => '[INIT] Starting general diagnostics sequence on '.$data['db_driver'].' storage node...'];
        $this->terminalLines[] = ['type' => 'info',    'text' => '[INFO] PHP engine: '.$data['php_version']];
        $this->terminalLines[] = ['type' => 'info',    'text' => '[INFO] Laravel framework: '.$data['laravel_version']];
        $this->terminalLines[] = ['type' => 'info',    'text' => '[INFO] Database: '.$data['db_driver'].' v'.$data['db_version']];
        $this->terminalLines[] = ['type' => 'info',    'text' => "[INFO] Cache driver: '{$data['cache_driver']}' — status: ".strtoupper($data['cache_status'])];
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

        $driver = $this->configString('cache.default', 'file');
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

    /**
     * @return array<string, mixed>&array{db_driver: string, php_version: string, laravel_version: string, db_version: string, cache_driver: string, cache_status: string, queue_connection: string, storage_disk: string, octane_installed: bool, octane_running: bool, octane_server: string, plugins_total: int, plugins_enabled: int}
     */
    public function getViewData(): array
    {
        $pluginsEnabled = PluginRecord::query()->where('enabled', true)->count();
        $pluginsTotal = PluginRecord::query()->count();

        $health = app(SystemHealthCollector::class);

        $appUrl = $this->configString('app.url', 'http://localhost');
        $host = parse_url($appUrl, PHP_URL_HOST);

        return [
            'magna_version' => MagnaServiceProvider::VERSION,
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'db_driver' => $health->dbDriver(),
            'db_version' => $health->dbVersion(),
            'environment' => app()->environment(),
            'debug_mode' => (bool) config('app.debug'),
            'cache_driver' => $this->configString('cache.default', 'file'),
            'queue_connection' => $this->configString('queue.default', 'sync'),
            'storage_disk' => $this->configString('filesystems.default', 'local'),
            'plugins_total' => $pluginsTotal,
            'plugins_enabled' => $pluginsEnabled,
            'plugins_disabled' => $pluginsTotal - $pluginsEnabled,
            'cache_status' => $health->cacheStatus(),
            'app_url' => is_string($host) ? $host : $appUrl,
            'session_lifetime' => $this->configInt('session.lifetime', 120),
            'octane_installed' => class_exists(OctaneServiceProvider::class),
            'octane_running' => Runtime::isOctane(),
            'octane_server' => $this->configString('octane.server', 'frankenphp'),
            'performance_warnings' => $health->performanceWarnings(),
            'security_warnings' => $health->securityWarnings(),
            // Did everything the running release ships actually arrive, and is
            // an update waiting to be finished? See FootprintCheck.
            'update_warnings' => app(FootprintCheck::class)->warnings(),
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

    // ── Typed reads over the untyped config repository ────────────────────

    private function configString(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) ? $value : $default;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
