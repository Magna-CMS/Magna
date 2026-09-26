<?php

declare(strict_types=1);

namespace Magna\Updater\Run;

use Illuminate\Contracts\Events\Dispatcher;
use Magna\Updater\CoreUpdateProgress;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\Engine\PathGuard;
use Magna\Updater\Engine\StagedSwap;
use Magna\Updater\Events\CoreUpdateFailed;
use Magna\Updater\Footprint\InstalledFootprint;
use Magna\Updater\MaintenanceWindow;
use Magna\Updater\UpdatePaths;
use Magna\Updater\UpdateRunLog;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * Put a switched run back the way it was: every path the journal says was
 * swapped or removed is renamed back, the boot guard is disarmed, the draft
 * footprint is dropped, and the site comes up on the previous release.
 *
 * One implementation for the three callers that need it — the old side when
 * its engine throws, the finalizer when the new code fails its boot check,
 * and the shell when a person asks — so they cannot disagree about what a
 * rollback is.
 */
final class RunRollback
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly UpdatePaths $paths,
        private readonly PathGuard $guard,
        private readonly InstalledFootprint $footprint,
        private readonly MaintenanceWindow $maintenance,
        private readonly Dispatcher $events,
        private readonly UpdateRunLog $log,
    ) {}

    public static function bootGuardMarker(UpdatePaths $paths): string
    {
        return $paths->updatesDir().'/boot-guard.json';
    }

    /** Arm the pure-PHP guard in bootstrap/update/boot-guard.php for this run. */
    public function armBootGuard(UpdateJournal $journal): void
    {
        $this->files->dumpFile(self::bootGuardMarker($this->paths), (string) json_encode([
            'run' => $journal->runId(),
            'armed_at' => date('c'),
        ]));
    }

    public function disarmBootGuard(): void
    {
        $this->files->remove([self::bootGuardMarker($this->paths), self::bootGuardMarker($this->paths).'.tripped']);
    }

    /**
     * @param  string  $reason  what went wrong, for the journal, the log and the admin
     * @return RunOutcome rolled back, or needs-attention when even that failed
     */
    public function rollBack(UpdateJournal $journal, string $reason): RunOutcome
    {
        $this->log->resume($journal->runId());
        $this->log->line('Rolling back: '.$reason);

        $swap = new StagedSwap($this->files, $this->paths, $this->guard, $journal);

        try {
            $swap->unswapAll();
        } catch (Throwable $e) {
            $message = "The update failed ({$reason}) and the rollback could not finish: {$e->getMessage()}. "
                .'The live tree may mix two releases. Run `php artisan magna:core:rollback` once the cause is fixed; the previous files are kept beside the live paths (`.replaced-'.$journal->runId().'`).';

            $journal->transition(RunState::NeedsAttention, ['outcome' => $message]);
            $this->log->line($message);
            CoreUpdateProgress::set(CoreUpdateState::Failed, $message, 100, $journal->string('to'));

            return RunOutcome::needsAttention($message);
        }

        $this->disarmBootGuard();
        $this->footprint->discardDraft();
        $this->maintenance->leave();

        $message = "The update to v{$journal->string('to')} did not complete and the previous release was put back: {$reason}";

        $journal->transition(RunState::RolledBack, ['outcome' => $message]);
        $this->log->line('Rolled back; the previous release is live again.');
        CoreUpdateProgress::set(CoreUpdateState::Failed, $message, 100, $journal->string('to'));
        $this->events->dispatch(new CoreUpdateFailed($journal->string('from') ?? '', $journal->string('to') ?? '', $reason, rolledBack: true));

        return RunOutcome::rolledBack($message);
    }
}
