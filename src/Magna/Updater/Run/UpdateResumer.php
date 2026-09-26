<?php

declare(strict_types=1);

namespace Magna\Updater\Run;

use Magna\Updater\CoreUpdateProgress;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\Engine\PathGuard;
use Magna\Updater\Engine\StagedSwap;
use Magna\Updater\MaintenanceWindow;
use Magna\Updater\UpdatePaths;
use Magna\Updater\UpdateRunLog;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Whoever finds an unfinished run, from wherever they found it, comes here.
 *
 * The admin's progress poll, the finalize job on a fresh worker, the
 * scheduler's minute tick and `magna:core:resume` all ask the same
 * question — is there a run waiting, and what does it need? — and get the
 * same answer, so an update never depends on the one process that started
 * it surviving.
 */
final class UpdateResumer
{
    public function __construct(
        private readonly UpdatePaths $paths,
        private readonly UpdateFinalizer $finalizer,
        private readonly RunRollback $rollback,
        private readonly MaintenanceWindow $maintenance,
        private readonly Filesystem $files,
        private readonly PathGuard $guard,
        private readonly UpdateRunLog $log,
    ) {}

    public function pending(): ?UpdateJournal
    {
        return UpdateJournal::latestPending($this->paths);
    }

    public function resume(): RunOutcome
    {
        $journal = $this->pending();

        if ($journal === null) {
            return RunOutcome::nothing();
        }

        $state = $journal->state();

        // Switching, and the engine has not yet said it is done. Its process
        // holds the run while it works; one that died mid-switch left a tree
        // that is neither release, and the only right move is back.
        if ($state === RunState::Swapped) {
            if (! $journal->isAbandoned()) {
                return RunOutcome::busy();
            }

            if (! $journal->acquire()) {
                return RunOutcome::busy();
            }

            try {
                return $this->rollback->rollBack($journal, 'the switch stopped partway and its process is gone');
            } finally {
                $journal->release();
            }
        }

        // The new release is live: only its own code may finish, and the
        // finalizer says so itself if this process is not it.
        if ($state->isPostSwitch()) {
            return $this->finalizer->finalize($journal);
        }

        // Nothing of the new release is live. A run still being worked on
        // is left alone; one whose process died is closed out.
        if (! $journal->isAbandoned()) {
            return RunOutcome::busy();
        }

        return $this->abandon($journal);
    }

    /**
     * Undo a switched run on request: the previous release comes back and
     * the site comes up. Nothing to do for a run that never switched or one
     * that already finished.
     */
    public function rollback(?string $runId = null): RunOutcome
    {
        $journal = $runId === null ? $this->pending() : UpdateJournal::open($this->paths, $runId);

        if ($journal === null) {
            return RunOutcome::nothing();
        }

        $state = $journal->state();

        if ($state === RunState::Completed) {
            return RunOutcome::failed("Run {$journal->runId()} finished; a completed update is not rolled back this way. Re-upload the previous release archive instead.");
        }

        if ($state->isTerminal()) {
            return RunOutcome::failed("Run {$journal->runId()} is already over ({$state->value}); nothing to roll back.");
        }

        if (! $journal->acquire()) {
            return RunOutcome::busy();
        }

        try {
            if (! $state->isPostSwitch() && $state !== RunState::Down) {
                return $this->abandon($journal);
            }

            return $this->rollback->rollBack($journal, 'rolled back on request');
        } finally {
            $journal->release();
        }
    }

    /** A run that stopped before switching: discard what it staged, lift maintenance if it got that far, and close it. */
    private function abandon(UpdateJournal $journal): RunOutcome
    {
        if (! $journal->acquire()) {
            return RunOutcome::busy();
        }

        try {
            $this->log->resume($journal->runId());

            $swap = new StagedSwap($this->files, $this->paths, $this->guard, $journal);

            foreach ($journal->paths() as $relative => $info) {
                if (($info['state'] ?? null) === 'staged') {
                    $swap->discardStaged($relative);
                }
            }

            if ($journal->state() === RunState::Down) {
                $this->maintenance->leave();
            }

            $message = "The update to v{$journal->string('to')} stopped before switching (run {$journal->runId()}); nothing of it is live.";
            $journal->transition(RunState::Failed, ['outcome' => $message]);
            $this->log->line($message);
            CoreUpdateProgress::set(CoreUpdateState::Failed, $message, 100, $journal->string('to'));

            return RunOutcome::failed($message);
        } finally {
            $journal->release();
        }
    }
}
