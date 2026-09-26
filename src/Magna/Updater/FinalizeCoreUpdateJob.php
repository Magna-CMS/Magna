<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Magna\Updater\Run\RunOutcome;
use Magna\Updater\Run\UpdateResumer;

/**
 * One of the carriers that finish a switched update — the one a queue
 * provides. Dispatched by the old side right after the switch; picked up by
 * whichever worker comes next.
 *
 * That worker may be the very one that performed the switch, still holding
 * the previous release's classes: `queue:restart` has told it to exit after
 * its current job, but it can win one more race. UpdateResumer notices
 * (the running version is not the journal's target) and this job simply
 * releases itself for a fresh worker to take.
 */
class FinalizeCoreUpdateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 5;

    public function handle(UpdateResumer $resumer): void
    {
        $outcome = $resumer->resume();

        if ($outcome->is(RunOutcome::STALE_CODE)) {
            Log::info('[magna.updater] '.$outcome->message.' Releasing the finalize job for a worker on the new code.');
            $this->release(10);

            return;
        }

        if ($outcome->is(RunOutcome::BUSY)) {
            $this->release(15);

            return;
        }

        Log::info('[magna.updater] Finalize job: '.$outcome->message);
    }
}
