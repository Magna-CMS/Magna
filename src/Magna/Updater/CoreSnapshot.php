<?php

declare(strict_types=1);

namespace Magna\Updater;

use Symfony\Component\Filesystem\Filesystem;

/**
 * The file-level rollback point a core update stands on: snapshot the
 * core-owned paths before the overlay, mirror them back if it goes wrong.
 * Extracted from CoreUpdater (per the collaborator pattern) so the snapshot
 * mechanics are unit-testable apart from the apply orchestration — WHEN to
 * restore (and what to tell the admin when even the restore fails) stays
 * with the orchestrator.
 *
 * File-level only, deliberately: rollback restores files, never the
 * database. If `migrate --force` failed partway, the site owner's own DB
 * backup is the recovery path — see the scope note on CoreUpdater.
 */
class CoreSnapshot
{
    public function __construct(private readonly Filesystem $files) {}

    /** Snapshot the core-owned paths to a timestamped backup directory. */
    public function create(): string
    {
        $backupPath = storage_path('app/magna-updates/backups/'.now()->format('Y_m_d_His'));

        foreach (CoreUpdater::coreOwnedPaths() as $relative) {
            $source = base_path($relative);
            if (! is_dir($source) && ! is_file($source)) {
                continue;
            }
            $this->files->mirror($source, $backupPath.'/'.$relative, null, ['override' => true]);
        }

        return $backupPath;
    }

    public function restore(string $backupPath): void
    {
        foreach (CoreUpdater::coreOwnedPaths() as $relative) {
            $source = $backupPath.'/'.$relative;
            if (! is_dir($source) && ! is_file($source)) {
                continue;
            }
            $this->files->mirror($source, base_path($relative), null, ['override' => true, 'delete' => true]);
        }
    }
}
