<?php

declare(strict_types=1);

namespace Magna\Updater;

/**
 * Starts a core update and keeps it from silently going nowhere.
 *
 * CoreUpdateJob is queued so the admin request returns immediately, but a large
 * share of Magna installs never run `queue:work` (QUEUE_CONNECTION=database with
 * no supervised worker is the default state of a fresh install). On those sites
 * the job sat in the `jobs` table forever while the panel showed "Starting…" —
 * the update looked broken when the queue was.
 *
 * So: the release is recorded before dispatch, and if nothing has picked the job
 * up after a short grace period, the polling request carries the apply out
 * itself. That is the same work `sync` queues have always done inside the
 * request, so it introduces no capability the app didn't already have.
 */
final class CoreUpdateStarter
{
    /**
     * How long a queued apply may sit untouched before it is treated as
     * unqueueable. Long enough that a busy but working worker gets there first;
     * short enough that an admin isn't left watching a dead progress bar.
     */
    public const WORKER_GRACE_SECONDS = 20;

    public function __construct(private readonly CoreUpdater $updater) {}

    public function start(PendingCoreUpdate $pending): void
    {
        CoreUpdateProgress::markQueued($pending);

        CoreUpdateJob::dispatch(
            $pending->version,
            $pending->zipUrl,
            $pending->expectedSha256,
            $pending->force,
            $pending->checksumSignature,
        );
    }

    /**
     * True when an apply is still only queued after the grace period and the
     * release is still there to be applied — i.e. no worker is coming.
     */
    public function isStalled(): bool
    {
        $progress = CoreUpdateProgress::read();

        return $progress['state'] === CoreUpdateState::Queued->value
            && $progress['waiting_seconds'] >= self::WORKER_GRACE_SECONDS
            && CoreUpdateProgress::pending() !== null;
    }

    /**
     * Applies the stalled release in the current request. Returns null when
     * there is nothing pending — including the case where a concurrent poll
     * took it first, since takePending() consumes the record, which is what
     * keeps a 2-second poll from starting the apply twice.
     */
    public function applyStalledInline(): ?CoreUpdateState
    {
        $pending = CoreUpdateProgress::takePending();

        if ($pending === null) {
            return null;
        }

        // A download + overlay + migrate run easily exceeds the default 30s;
        // the queued path had `$timeout = 1800` for the same reason.
        if (function_exists('set_time_limit')) {
            set_time_limit(CoreUpdateJob::TIMEOUT_SECONDS);
        }

        // Written before the (long) apply starts so the admin's next poll
        // explains why this is taking a whole request, and so the reason stays
        // visible in the activity log afterwards.
        CoreUpdateProgress::set(
            CoreUpdateState::Running,
            'No background worker found — applying in this browser session…',
            2,
            $pending->version,
        );

        return $this->updater->apply(
            $pending->version,
            $pending->zipUrl,
            $pending->expectedSha256,
            $pending->force,
            $pending->checksumSignature,
        );
    }
}
