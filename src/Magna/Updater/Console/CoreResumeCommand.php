<?php

declare(strict_types=1);

namespace Magna\Updater\Console;

use Illuminate\Console\Command;
use Magna\Updater\Run\RunOutcome;
use Magna\Updater\Run\UpdateResumer;

/**
 * Finish an update whose switch is done, or close out one that stopped
 * before switching. Scheduled every minute while a run is pending; also the
 * thing to run from a shell when the panel is not reachable.
 *
 * Usage:
 *   php artisan magna:core:resume
 */
class CoreResumeCommand extends Command
{
    protected $signature = 'magna:core:resume';

    protected $description = 'Finish a switched core update under the new code, or clear one that stopped early';

    public function handle(UpdateResumer $resumer): int
    {
        $outcome = $resumer->resume();

        return match ($outcome->kind) {
            RunOutcome::COMPLETED => $this->report($outcome, 'info', self::SUCCESS),
            RunOutcome::NOTHING, RunOutcome::BUSY => $this->report($outcome, 'line', self::SUCCESS),
            RunOutcome::STALE_CODE => $this->report($outcome, 'warn', 2),
            default => $this->report($outcome, 'error', self::FAILURE),
        };
    }

    private function report(RunOutcome $outcome, string $method, int $code): int
    {
        $this->{$method}($outcome->message);

        return $code;
    }
}
