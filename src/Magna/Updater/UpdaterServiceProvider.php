<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Magna\Support\ConfigDrift;
use Magna\Updater\Console\CheckForUpdatesCommand;
use Magna\Updater\Console\CoreRepairCommand;
use Magna\Updater\Console\CoreResumeCommand;
use Magna\Updater\Console\CoreRollbackCommand;
use Magna\Updater\Console\CoreStatusCommand;
use Magna\Updater\Run\FirstRequestFinalizer;
use Magna\Updater\Run\UpdateJournal;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The core updater — always registered, never a plugin. Keeps the site's
 * "is an update available" state fresh via a scheduled heartbeat check-in
 * against Update Manager, and exposes UpdateCheckClient for the on-demand
 * check triggered from System Info.
 *
 * Deliberately not a plugin: the thing that keeps a site updatable can't
 * itself be disabled through the plugin UI (see docs/updates-architecture.md).
 */
class UpdaterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UpdateCheckClient::class);
        $this->app->singleton(Filesystem::class);

        // The one place the updater learns where the install lives. A test
        // rebinds this at a fixture directory and the whole apply — snapshot,
        // download, overlay, cleanup — runs against that instead of the tree
        // the test suite itself is executing from.
        //
        // Under the test environment the storage side moves to the framework's
        // scratch directory even when nothing rebinds it: a test that stops at
        // the first guard still opens a run log, and a hundred of those used
        // to land in the developer's own storage/app.
        $this->app->singleton(UpdatePaths::class, fn (): UpdatePaths => new UpdatePaths(
            $this->app->basePath(),
            $this->app->runningUnitTests()
                ? $this->app->storagePath('framework/testing')
                : $this->app->storagePath(),
        ));

        // One run log per process (per request under Octane): the orchestrator,
        // the maintenance window, the runtime refresh and the finalizer all
        // write the same run's log, and must be writing the same file.
        $this->app->scoped(UpdateRunLog::class);

        $this->app->bind(ConfigDrift::class, fn (): ConfigDrift => new ConfigDrift($this->app->basePath()));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckForUpdatesCommand::class,
                CoreStatusCommand::class,
                CoreResumeCommand::class,
                CoreRollbackCommand::class,
                CoreRepairCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('magna:updater:check')
                ->cron('0 */12 * * *')
                ->withoutOverlapping();

            // A switched update waits for a process on the new code. On a host
            // with cron and no worker, this is that process: every minute while
            // a run is pending, nothing otherwise. The site is in maintenance
            // mode for exactly that window, and the scheduler skips every
            // event while the site is down unless told otherwise — told here,
            // because down is the only time this event has work to do.
            $schedule->command('magna:core:resume')
                ->everyMinute()
                ->withoutOverlapping()
                ->evenInMaintenanceMode()
                ->when(fn (): bool => UpdateJournal::latestPending($this->app->make(UpdatePaths::class)) !== null);
        });

        // The first request that boots the new release finishes a switched
        // update, whoever sent it — see FirstRequestFinalizer. Never from the
        // console: the commands there say explicitly what happens to a run.
        if (! $this->app->runningInConsole()) {
            $this->app->make(FirstRequestFinalizer::class)->run();
        }
    }
}
