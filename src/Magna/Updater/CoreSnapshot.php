<?php

declare(strict_types=1);

namespace Magna\Updater;

use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * The file-level rollback point a core update stands on: snapshot the paths
 * the release will replace or remove before the overlay, mirror them back if
 * it goes wrong. Extracted from CoreUpdater (per the collaborator pattern) so
 * the snapshot mechanics are unit-testable apart from the apply orchestration
 * — WHEN to restore (and what to tell the admin when even the restore fails)
 * stays with the orchestrator.
 *
 * The snapshot records the list it was taken with, in paths.json beside the
 * files, so a restore — by this class or by a person with a shell — puts back
 * exactly what was taken and nothing else. A release manifest can name paths
 * the updater's own list does not; the two must never disagree about what a
 * backup holds.
 *
 * File-level only, deliberately: rollback restores files, never the
 * database. If `migrate --force` failed partway, the site owner's own DB
 * backup is the recovery path — see the scope note on CoreUpdater.
 *
 * Known asymmetry, kept in view for the journaled swap that replaces this: a
 * path that did not exist before the update is not in the snapshot, so a
 * restore reverts the paths that were there and leaves a newly delivered one
 * in place. Harmless for the paths this list holds today, all of which every
 * install has.
 */
class CoreSnapshot
{
    public const PATHS_FILE = 'paths.json';

    public function __construct(
        private readonly Filesystem $files,
        private readonly UpdatePaths $paths,
    ) {}

    /**
     * Snapshot the given paths to a timestamped backup directory.
     *
     * @param  list<string>  $paths  relative to the install root; the updater's own list when omitted
     */
    public function create(?array $paths = null): string
    {
        $paths ??= CoreUpdater::coreOwnedPaths();
        $backupPath = $this->paths->backupsDir().'/'.now()->format('Y_m_d_His');

        $this->files->mkdir($backupPath, 0700);

        foreach ($paths as $relative) {
            $source = $this->paths->base($relative);

            if (is_dir($source)) {
                $this->files->mirror($source, $backupPath.'/'.$relative, null, ['override' => true]);
            } elseif (is_file($source)) {
                $this->files->copy($source, $backupPath.'/'.$relative, true);
            }
        }

        $this->files->dumpFile($backupPath.'/'.self::PATHS_FILE, (string) json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Core source, wanted by the web user alone. Best effort: a host that
        // will not take the mode (Windows, some mounts) keeps the default.
        try {
            $this->files->chmod($backupPath, 0700);
        } catch (Throwable) {
            // The snapshot itself is complete; only its mode is not.
        }

        return $backupPath;
    }

    /**
     * Put back what the snapshot holds — by the list it was taken with, or
     * the updater's own list for a snapshot from before lists were recorded.
     */
    public function restore(string $backupPath): void
    {
        foreach ($this->pathsOf($backupPath) as $relative) {
            $source = $backupPath.'/'.$relative;

            if (is_dir($source)) {
                $this->files->mirror($source, $this->paths->base($relative), null, ['override' => true, 'delete' => true]);
            } elseif (is_file($source)) {
                $this->files->copy($source, $this->paths->base($relative), true);
            }
        }
    }

    /** @return list<string> */
    public function pathsOf(string $backupPath): array
    {
        $file = $backupPath.'/'.self::PATHS_FILE;

        if (! is_file($file)) {
            return CoreUpdater::coreOwnedPaths();
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded)) {
            return CoreUpdater::coreOwnedPaths();
        }

        return array_values(array_filter($decoded, 'is_string'));
    }
}
