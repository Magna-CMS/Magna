<?php

declare(strict_types=1);

namespace Magna\Admin\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Magna\Admin\AdminAssetPublisher;
use Magna\Plugins\PluginMigrator;
use Magna\Plugins\PluginRecord;
use Magna\Updater\RuntimeRefresh;
use Throwable;

/**
 * The System Info console's real maintenance actions: the commands an operator
 * would otherwise need shell access for after a deploy — run migrations,
 * rebuild caches, publish admin assets, restart the queue. A hosting panel's
 * CLI PHP is not always the web PHP (missing extensions, different ini), so
 * running these inside a web request is not a workaround, it is the more
 * reliable SAPI.
 *
 * Its own trait for the same reason as DrivesCoreUpdate: SystemInfoPage
 * delegates whole concerns rather than growing past the line ceiling
 * ArchitectureTest enforces. Every action gates on settings.manage in the
 * method body — Livewire methods are callable regardless of whether their
 * button rendered — and prints what actually ran, line by line, exit code
 * included, into the page's terminal panel. Dependencies arrive as action
 * parameters, which Livewire resolves from the container (the seam the
 * service-location ratchet points to).
 */
trait RunsMaintenanceActions
{
    public function runMigrations(PluginMigrator $migrator): void
    {
        $this->authorizeSettingsManage();

        $failed = false;

        $this->terminalLines[] = ['type' => 'cmd', 'text' => 'php artisan migrate --force'];

        try {
            $exit = Artisan::call('migrate', ['--force' => true]);
            $this->printCommandOutput(Artisan::output());

            if ($exit !== 0) {
                $failed = true;
                $this->terminalLines[] = ['type' => 'error', 'text' => "[ERROR] migrate exited with code {$exit}."];
            }
        } catch (Throwable $e) {
            $failed = true;
            $this->terminalLines[] = ['type' => 'error', 'text' => '[ERROR] '.$e->getMessage()];
        }

        // Each plugin on its own: one plugin's broken migration must not hide
        // whether the others applied (same shape as PluginUpgradeHooks::run()).
        foreach (PluginRecord::query()->where('enabled', true)->orderBy('name')->get() as $record) {
            $this->terminalLines[] = ['type' => 'cmd', 'text' => "php artisan migrate --force  # plugin {$record->name}"];

            try {
                $output = $migrator->run((string) $record->base_path);

                if ($output === null) {
                    $this->terminalLines[] = ['type' => 'info', 'text' => '[INFO] No migrations shipped.'];
                } else {
                    $this->printCommandOutput($output);
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->terminalLines[] = ['type' => 'error', 'text' => '[ERROR] '.$e->getMessage()];
            }
        }

        if ($failed) {
            $this->terminalLines[] = ['type' => 'error', 'text' => '[ERROR] Migrations finished with errors — see above.'];
            Notification::make()->title('Migrations finished with errors')->danger()->send();

            return;
        }

        $this->terminalLines[] = ['type' => 'success', 'text' => '[SUCCESS] All migrations are up to date.'];
        Notification::make()->title('Migrations complete')->success()->send();
    }

    public function rebuildCaches(Application $laravel, RuntimeRefresh $refresh): void
    {
        $this->authorizeSettingsManage();

        // Snapshot BEFORE clearing, so only what was warm is re-warmed — a
        // site that never caches is not made to start (UpdateFinalizer's rule).
        $configWasCached = $laravel->configurationIsCached();
        $routesWereCached = $laravel->routesAreCached();

        $this->terminalLines[] = ['type' => 'cmd', 'text' => 'php artisan cache:clear && php artisan optimize:clear'];

        try {
            Artisan::call('cache:clear');
            $this->terminalLines[] = ['type' => 'info', 'text' => '[INFO] Application cache cleared.'];

            $refresh->clearCaches();
            $this->terminalLines[] = ['type' => 'info', 'text' => '[INFO] Config, route, view, event and Filament component caches cleared; asset publish requested.'];

            foreach ([
                'config:cache' => $configWasCached,
                'route:cache' => $routesWereCached,
            ] as $command => $wasWarm) {
                if (! $wasWarm) {
                    continue;
                }

                Artisan::call($command);
                $this->terminalLines[] = ['type' => 'info', 'text' => "[INFO] `{$command}` re-warmed."];
            }

            $this->terminalLines[] = ['type' => 'success', 'text' => '[SUCCESS] Caches rebuilt.'];
            Notification::make()->title('Caches rebuilt')->success()->send();
        } catch (Throwable $e) {
            $this->terminalLines[] = ['type' => 'error', 'text' => '[ERROR] '.$e->getMessage()];
            Notification::make()->title('Cache rebuild failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function publishAssets(AdminAssetPublisher $assets): void
    {
        $this->authorizeSettingsManage();

        $this->terminalLines[] = ['type' => 'cmd', 'text' => 'php artisan filament:assets'];

        try {
            $assets->publish();
            $this->printCommandOutput(Artisan::output());
            $this->terminalLines[] = ['type' => 'success', 'text' => '[SUCCESS] Admin assets published.'];
            Notification::make()->title('Assets published')->success()->send();
        } catch (Throwable $e) {
            $this->terminalLines[] = ['type' => 'error', 'text' => '[ERROR] '.$e->getMessage()];
            Notification::make()->title('Asset publish failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function restartQueue(): void
    {
        $this->authorizeSettingsManage();

        $this->terminalLines[] = ['type' => 'cmd', 'text' => 'php artisan queue:restart'];

        try {
            Artisan::call('queue:restart');
            $this->terminalLines[] = ['type' => 'success', 'text' => '[SUCCESS] Workers will exit after their current job and be restarted by the supervisor.'];
            Notification::make()->title('Queue restart signalled')->success()->send();
        } catch (Throwable $e) {
            $this->terminalLines[] = ['type' => 'error', 'text' => '[ERROR] '.$e->getMessage()];
            Notification::make()->title('Queue restart failed')->body($e->getMessage())->danger()->send();
        }
    }

    /** Every non-empty line of a command's real output, verbatim. */
    private function printCommandOutput(string $output): void
    {
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if (trim($line) !== '') {
                $this->terminalLines[] = ['type' => 'info', 'text' => $line];
            }
        }
    }
}
