<?php

declare(strict_types=1);

namespace Magna\Updater\Console;

use Illuminate\Console\Command;
use Magna\MagnaServiceProvider;
use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\Run\RunOutcome;
use Magna\Updater\Run\UpdateResumer;

/**
 * Re-apply the release this install already runs, from its archive.
 *
 * For a site an older updater brought here: the code arrived (it always
 * does) and nothing recorded whether everything else the release ships
 * arrived with it. The archive is verified against the checksum you give,
 * applied exactly as an update is, and the delivery recorded — after which
 * System Info stops warning.
 *
 * Usage:
 *   php artisan magna:core:repair --archive=/path/magna-cms-v1.4.4.zip --sha256=<64 hex>
 *
 * The checksum is the .sha256 sidecar published beside the archive; type it
 * from there, not from the archive itself, or the check proves nothing.
 */
class CoreRepairCommand extends Command
{
    protected $signature = 'magna:core:repair
        {--archive= : Path to the release archive for the running version}
        {--sha256= : The SHA-256 published for that archive}';

    protected $description = 'Re-apply the running core release from its archive and record the delivery';

    public function handle(CoreUpdater $updater, UpdateResumer $resumer): int
    {
        $archive = $this->option('archive');
        $sha256 = $this->option('sha256');

        if (! is_string($archive) || $archive === '' || ! is_string($sha256) || $sha256 === '') {
            $this->error('Both --archive and --sha256 are required.');

            return self::INVALID;
        }

        if (! is_file($archive)) {
            $this->error("No archive at {$archive}.");

            return self::INVALID;
        }

        $this->line('Repairing v'.MagnaServiceProvider::VERSION.' from '.$archive);

        $state = $updater->repair($archive, strtolower(trim($sha256)));

        if ($state === CoreUpdateState::Switched) {
            // Same version on both sides of the switch, so this process may
            // finish what it started.
            $outcome = $resumer->resume();

            if (! $outcome->is(RunOutcome::COMPLETED)) {
                $this->error($outcome->message);

                return self::FAILURE;
            }

            $this->info($outcome->message);

            return self::SUCCESS;
        }

        $progress = CoreUpdater::progress();

        if ($state === CoreUpdateState::Completed) {
            $this->info($progress['message']);

            return self::SUCCESS;
        }

        $this->error($progress['message'] !== '' ? $progress['message'] : 'The repair did not complete.');

        return self::FAILURE;
    }
}
