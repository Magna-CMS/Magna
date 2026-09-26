<?php

declare(strict_types=1);

namespace Magna\Updater\Run;

/**
 * Where one update run is, as written in its journal.
 *
 * The old side (the release the site runs) carries a run from Created to
 * FinalizePending; the new side (the release just laid down) carries it from
 * there to Completed. Any state from Swapped onward means the new release's
 * files are the live ones.
 */
enum RunState: string
{
    case Created = 'created';
    case Downloaded = 'downloaded';
    case Verified = 'verified';
    case Extracted = 'extracted';
    case Planned = 'planned';
    case Staged = 'staged';
    case Down = 'down';
    case Swapped = 'swapped';
    case FinalizePending = 'finalize_pending';
    case Finalizing = 'finalizing';
    case BootHealthy = 'boot_healthy';
    case Migrated = 'migrated';
    case PluginsNotified = 'plugins_notified';
    case CachesCleared = 'caches_cleared';
    case Committed = 'committed';
    case Completed = 'completed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case NeedsAttention = 'needs_attention';
    case RolledBackByBootGuard = 'rolled_back_by_boot_guard';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::RolledBack, self::NeedsAttention, self::RolledBackByBootGuard => true,
            default => false,
        };
    }

    /** The new release's files are live: only the new code may finish, and only a rename-back can undo. */
    public function isPostSwitch(): bool
    {
        return match ($this) {
            self::Swapped, self::FinalizePending, self::Finalizing, self::BootHealthy, self::Migrated, self::PluginsNotified, self::CachesCleared, self::Committed => true,
            default => false,
        };
    }

    /** Nothing of the new release is live yet; abandoning the run costs nothing but temp files. */
    public function isPreSwitch(): bool
    {
        return match ($this) {
            self::Created, self::Downloaded, self::Verified, self::Extracted, self::Planned, self::Staged, self::Down => true,
            default => false,
        };
    }
}
