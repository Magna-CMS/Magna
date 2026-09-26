<?php

declare(strict_types=1);

namespace Magna\Updater\Run;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Magna\Updater\UpdatePaths;
use Throwable;

/**
 * The first request that boots the new release finishes the update,
 * whoever sent it.
 *
 * A switched update is finished under the new code by whichever process
 * gets there first: the admin's poll, the finalize job, the scheduler's
 * minute tick, the CLI. Every one of those has a way of not arriving — a
 * poll without its bypass cookie is answered 503 before it reaches the page,
 * a job needs a worker, a tick needs cron — and a site whose update has
 * switched but not finished is a site in maintenance mode with nobody
 * coming. Every request boots the application before the maintenance
 * middleware turns it away, so a request is always coming: this finishes the
 * run from it, and the visitor who sent it then sees the updated site rather
 * than the maintenance page.
 *
 * Idle cost is one stat of the boot-guard marker, which exists only between
 * switch and commit. The journal's lock keeps two requests from finishing
 * the same run, and nothing here throws: a request is served whatever state
 * the site is in. Under Octane the application boots once per worker, so
 * this runs once there; the other carriers remain.
 */
final class FirstRequestFinalizer
{
    public function __construct(
        private readonly UpdatePaths $paths,
        private readonly UpdateResumer $resumer,
        private readonly ExceptionHandler $exceptions,
    ) {}

    /** @return RunOutcome|null the outcome, or null when there was nothing waiting */
    public function run(): ?RunOutcome
    {
        if (! is_file(RunRollback::bootGuardMarker($this->paths))) {
            return null;
        }

        try {
            return $this->resumer->resume();
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            return null;
        }
    }
}
