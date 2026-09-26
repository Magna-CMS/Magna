<?php

declare(strict_types=1);

namespace Magna\Updater;

/** Lifecycle of a core update apply, surfaced to the UI. Mirrors Magna\Marketplace\InstallState. */
enum CoreUpdateState: string
{
    case Queued = 'queued';
    case Running = 'running';
    /**
     * The new release's files are live and the process that put them there
     * has stepped back: migrations, caches and the all-clear now belong to a
     * process running the new code (UpdateFinalizer, reached through
     * UpdateResumer). Still "in progress" as far as the panel is concerned.
     */
    case Switched = 'switched';
    case Completed = 'completed';
    case Failed = 'failed';
}
