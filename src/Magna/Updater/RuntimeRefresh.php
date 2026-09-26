<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Support\Facades\Artisan;
use Laravel\Octane\OctaneServiceProvider;
use Magna\Support\Runtime;
use Throwable;

/**
 * Make the process tree notice the files changed underneath it.
 *
 * Caches first. Then the two that were missing for a long time: a queue
 * worker that ran the apply keeps the previous release's classes in memory
 * and would autoload NEW files for anything it had not touched yet — one
 * process, two versions — so every worker is told to exit after its current
 * job. And opcache holds the old compiled files at the same paths; under
 * `validate_timestamps=0` nothing would ever notice the switch. The CLI's
 * opcache is separate from the web server's, so the reset only helps where
 * it runs in a web SAPI.
 */
final class RuntimeRefresh
{
    public function __construct(private readonly UpdateRunLog $log) {}

    /** Everything, in the order the old apply did it. */
    public function refresh(): void
    {
        $this->clearCaches();
        $this->restartWorkers();
        $this->resetOpcache();
        $this->reloadOctane();
    }

    public function clearCaches(): void
    {
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            $this->call($command);
        }

        // Filament caches its discovered panel components under
        // bootstrap/cache; a release that adds or removes a page must not
        // leave the old list answering.
        $this->call('filament:clear-cached-components');
    }

    public function restartWorkers(): void
    {
        $this->call('queue:restart');
    }

    public function resetOpcache(): void
    {
        if (PHP_SAPI !== 'cli' && function_exists('opcache_reset')) {
            @opcache_reset();
            $this->log->line('opcache reset');
        }
    }

    public function reloadOctane(): void
    {
        if (class_exists(OctaneServiceProvider::class) && Runtime::isOctane()) {
            $this->call('octane:reload');
        }
    }

    /** A cache that will not clear is a slower site, not a failed update. */
    private function call(string $command): void
    {
        try {
            Artisan::call($command);
        } catch (Throwable $e) {
            $this->log->line("`{$command}` failed: ".$e->getMessage());
        }
    }
}
