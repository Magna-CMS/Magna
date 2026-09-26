<?php

declare(strict_types=1);

namespace Magna\Updater\Console;

use Illuminate\Console\Command;
use Magna\Updater\Run\RunOutcome;
use Magna\Updater\Run\UpdateResumer;

/**
 * Put the previous release back after a switch that should not stand: the
 * new code does not boot on this host, a migration failed and the database
 * backup is going back in, or the operator simply wants out. Files only —
 * the database is never touched.
 *
 * Usage:
 *   php artisan magna:core:rollback            # the pending run
 *   php artisan magna:core:rollback <run-id>   # a specific one
 */
class CoreRollbackCommand extends Command
{
    protected $signature = 'magna:core:rollback {run? : The run to undo; the pending one when omitted}';

    protected $description = 'Rename the previous release back over a switched core update';

    public function handle(UpdateResumer $resumer): int
    {
        $run = $this->argument('run');
        $outcome = $resumer->rollback(is_string($run) && $run !== '' ? $run : null);

        if ($outcome->is(RunOutcome::ROLLED_BACK)) {
            $this->info($outcome->message);

            return self::SUCCESS;
        }

        if ($outcome->is(RunOutcome::NOTHING)) {
            $this->line($outcome->message);

            return self::SUCCESS;
        }

        $this->error($outcome->message);

        return self::FAILURE;
    }
}
